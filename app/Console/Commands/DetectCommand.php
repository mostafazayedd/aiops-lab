<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PrometheusClient;
use App\Services\BaselineService;
use App\Services\AnomalyDetector;
use App\Services\CorrelationEngine;
use App\Services\IncidentManager;
use App\Services\AlertService;

class DetectCommand extends Command
{
    protected $signature   = 'aiops:detect';
    protected $description = 'AIOps Detection Engine — continuously analyzes metrics and detects anomalies';

    protected PrometheusClient $prometheus;
    protected BaselineService  $baseline;
    protected AnomalyDetector  $detector;
    protected CorrelationEngine $correlation;
    protected IncidentManager  $manager;
    protected AlertService     $alert;

    protected int $cycleSeconds  = 25;
    protected int $cycleCount    = 0;

    public function handle(): void
    {
        $this->initServices();
        $this->printBanner();

        while (true) {
            $this->cycleCount++;
            $this->runCycle();
            $this->info("\n⏳ Sleeping {$this->cycleSeconds}s until next cycle...\n");
            sleep($this->cycleSeconds);
        }
    }

    // ─── Initialize all services ──────────────────────────────────────────────

    protected function initServices(): void
    {
        $this->prometheus  = new PrometheusClient();
        $this->baseline    = new BaselineService($this->prometheus);
        $this->detector    = new AnomalyDetector($this->prometheus, $this->baseline);
        $this->correlation = new CorrelationEngine();
        $this->manager     = new IncidentManager();
        $this->alert       = new AlertService();
    }

    // ─── Single detection cycle ───────────────────────────────────────────────

    protected function runCycle(): void
    {
        $this->info(str_repeat('─', 60));
        $this->info("🔄 Cycle #{$this->cycleCount} | " . now()->toDateTimeString());
        $this->info(str_repeat('─', 60));

        // Step 1: Record baseline observation
        $this->info('📊 Recording observation...');
        $this->baseline->recordObservation();

        // Step 2: Check if baseline is ready
        if (!$this->baseline->isReady()) {
            $this->warn('⏳ Not enough baseline data yet. Waiting for more observations...');
            return;
        }

        // Step 3: Fetch and display current metrics
        $metrics = $this->prometheus->getAllMetrics();
        $this->printMetrics($metrics);

        // Step 4: Detect anomalies
        $this->info('🔍 Running anomaly detection...');
        $result = $this->detector->detect();

        if ($result['total'] === 0) {
            $this->info('✅ No anomalies detected. System healthy.');
            return;
        }

        $this->warn("⚠️  {$result['total']} anomaly signal(s) detected!");
        foreach ($result['anomalies'] as $anomaly) {
            $this->warn("   → [{$anomaly['severity']}] {$anomaly['type']} on {$anomaly['endpoint']}");
        }

        // Step 5: Correlate into incidents
        $this->info('🔗 Correlating signals into incidents...');
        $incidents = $this->correlation->correlate($result);
        $this->info('   → ' . count($incidents) . ' incident(s) correlated');

        // Step 6: Save incidents
        $saved = $this->manager->saveIncidents($incidents);

        if (empty($saved)) {
            $this->info('🔕 All incidents suppressed (duplicates within 5 min window)');
        } else {
            $this->info('💾 ' . count($saved) . ' new incident(s) saved to incidents.json');
        }

        // Step 7: Emit alerts
        foreach ($saved as $incident) {
            $this->alert->emitAlert($incident);
        }

        // Step 8: Print summary
        $summary = $this->manager->getSummary();
        $this->info("📋 Incident Summary → Total: {$summary['total']} | Open: {$summary['open']} | Resolved: {$summary['resolved']}");
    }

    // ─── Print current metrics table ─────────────────────────────────────────

    protected function printMetrics(array $metrics): void
    {
        $this->info('📈 Current Metrics:');
        $this->table(
            ['Endpoint', 'Req/s', 'Err/s', 'p50 (s)', 'p95 (s)', 'p99 (s)'],
            array_map(function ($endpoint) use ($metrics) {
                return [
                    $endpoint,
                    round($metrics['request_rates'][$endpoint] ?? 0, 4),
                    round($metrics['error_rates'][$endpoint]   ?? 0, 4),
                    round($metrics['latency_percentiles'][$endpoint]['p50'] ?? 0, 4),
                    round($metrics['latency_percentiles'][$endpoint]['p95'] ?? 0, 4),
                    round($metrics['latency_percentiles'][$endpoint]['p99'] ?? 0, 4),
                ];
            }, ['/api/normal', '/api/slow', '/api/db', '/api/error', '/api/validate'])
        );
    }

    // ─── Print startup banner ─────────────────────────────────────────────────

    protected function printBanner(): void
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║         🤖 AIOps Detection Engine — STARTED             ║');
        $this->info('║         Cycle interval: ' . $this->cycleSeconds . 's                          ║');
        $this->info('║         Press Ctrl+C to stop                             ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info('');
    }
}
