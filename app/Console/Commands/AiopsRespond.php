<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AiopsRespond extends Command
{
    protected $signature = 'aiops:respond';
    protected $description = 'Automated Incident Response Engine';

    // Response policies
    private array $policies = [
        'LATENCY_SPIKE' => [
            'action'    => 'restart_service',
            'threshold' => 4000,
            'severity'  => 'HIGH',
        ],
        'ERROR_STORM' => [
            'action'    => 'send_alert',
            'threshold' => 20,
            'severity'  => 'CRITICAL',
        ],
        'TRAFFIC_SURGE' => [
            'action'    => 'scale_service',
            'threshold' => 100,
            'severity'  => 'MEDIUM',
        ],
        'DATABASE_ERROR' => [
            'action'    => 'failover_database',
            'threshold' => 5,
            'severity'  => 'CRITICAL',
        ],
        'VALIDATION_ERROR' => [
            'action'    => 'throttle_traffic',
            'threshold' => 10,
            'severity'  => 'LOW',
        ],
    ];

    public function handle(): void
    {
        $this->info('🚀 AIOps Automation Engine started...');
        $this->info('📊 Analyzing incidents from logs...');

        // Load logs
        $logsPath = storage_path('logs/aiops.log');
        if (!file_exists($logsPath)) {
            $this->error('No aiops.log found!');
            return;
        }

        $lines = file($logsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs  = array_filter(array_map(fn($l) => json_decode($l, true), $lines));

        // Detect incidents
        $incidents = $this->detectIncidents($logs);

        if (empty($incidents)) {
            $this->info('✅ No incidents detected. System is healthy.');
            return;
        }

        $this->info('⚠️  ' . count($incidents) . ' incident(s) detected!');

        // Respond to each incident
        $responses = [];
        foreach ($incidents as $incident) {
            $response = $this->respondToIncident($incident);
            $responses[] = $response;
            $this->displayResponse($response);
        }

        // Save responses
        $this->saveResponses($responses);
        $this->info('✅ Response log saved to storage/aiops/responses.json');
    }

    private function detectIncidents(array $logs): array
    {
        $incidents = [];
        $errors    = array_filter($logs, fn($l) => ($l['context']['severity'] ?? '') === 'error');
        $total     = count($logs);
        $errorCount = count($errors);

        // Error rate
        $errorRate = $total > 0 ? ($errorCount / $total) * 100 : 0;
        if ($errorRate > 20) {
            $incidents[] = [
                'incident_id'   => 'INC-' . now()->format('YmdHis') . '-001',
                'type'          => 'ERROR_STORM',
                'description'   => "Error rate is {$errorRate}% — exceeds 20% threshold",
                'error_rate'    => round($errorRate, 2),
                'detected_at'   => now()->toIso8601String(),
            ];
        }

        // Latency spike
        $latencies  = array_column(array_column($logs, 'context'), 'latency_ms');
        $avgLatency = count($latencies) > 0 ? array_sum($latencies) / count($latencies) : 0;
        if ($avgLatency > 4000) {
            $incidents[] = [
                'incident_id'   => 'INC-' . now()->format('YmdHis') . '-002',
                'type'          => 'LATENCY_SPIKE',
                'description'   => "Average latency is {$avgLatency}ms — exceeds 4000ms threshold",
                'avg_latency'   => round($avgLatency, 2),
                'detected_at'   => now()->toIso8601String(),
            ];
        }

        // Traffic surge
        if ($total > 1000) {
            $incidents[] = [
                'incident_id'   => 'INC-' . now()->format('YmdHis') . '-003',
                'type'          => 'TRAFFIC_SURGE',
                'description'   => "Total requests {$total} — exceeds 1000 threshold",
                'total_requests'=> $total,
                'detected_at'   => now()->toIso8601String(),
            ];
        }

        // Database errors
        $dbErrors = array_filter($errors, fn($l) => ($l['context']['error_category'] ?? '') === 'DATABASE_ERROR');
        if (count($dbErrors) > 5) {
            $incidents[] = [
                'incident_id'   => 'INC-' . now()->format('YmdHis') . '-004',
                'type'          => 'DATABASE_ERROR',
                'description'   => count($dbErrors) . ' DATABASE_ERROR events detected',
                'db_error_count'=> count($dbErrors),
                'detected_at'   => now()->toIso8601String(),
            ];
        }

        // Validation errors
        $valErrors = array_filter($errors, fn($l) => ($l['context']['error_category'] ?? '') === 'VALIDATION_ERROR');
        if (count($valErrors) > 10) {
            $incidents[] = [
                'incident_id'   => 'INC-' . now()->format('YmdHis') . '-005',
                'type'          => 'VALIDATION_ERROR',
                'description'   => count($valErrors) . ' VALIDATION_ERROR events detected',
                'val_error_count'=> count($valErrors),
                'detected_at'   => now()->toIso8601String(),
            ];
        }

        return $incidents;
    }

    private function respondToIncident(array $incident): array
    {
        $type   = $incident['type'];
        $policy = $this->policies[$type] ?? null;

        if (!$policy) {
            return $this->buildResponse($incident, 'no_action', 'FAILED', 'No policy defined for this incident type', false);
        }

        $action = $policy['action'];
        $result = $this->executeAction($action, $incident);

        // Escalation logic
        if (!$result['success'] || $policy['severity'] === 'CRITICAL') {
            return $this->escalate($incident, $action, $result);
        }

        return $this->buildResponse($incident, $action, 'SUCCESS', $result['message'], false);
    }

    private function executeAction(string $action, array $incident): array
    {
        // Simulate actions
        switch ($action) {
            case 'restart_service':
                $this->info("  🔄 Simulating service restart...");
                sleep(1);
                return ['success' => true, 'message' => 'Service restarted successfully. Latency normalized.'];

            case 'send_alert':
                $this->info("  📢 Sending alert to on-call team...");
                return ['success' => true, 'message' => 'Alert sent to on-call engineer via PagerDuty simulation.'];

            case 'scale_service':
                $this->info("  📈 Simulating horizontal scaling...");
                return ['success' => true, 'message' => 'Scaled from 1 to 3 instances. Load distributed.'];

            case 'failover_database':
                $this->info("  🗄️  Simulating database failover...");
                return ['success' => false, 'message' => 'Failover attempted but replica not available. Escalating.'];

            case 'throttle_traffic':
                $this->info("  🚦 Simulating traffic throttling...");
                return ['success' => true, 'message' => 'Rate limiting applied. Max 100 req/min per client.'];

            default:
                return ['success' => false, 'message' => 'Unknown action: ' . $action];
        }
    }

    private function escalate(array $incident, string $action, array $result): array
    {
        $this->warn("  🚨 ESCALATING incident {$incident['incident_id']} to CRITICAL_ALERT!");
        return $this->buildResponse(
            $incident,
            $action . ' → CRITICAL_ALERT',
            'ESCALATED',
            'Automated action failed or severity is CRITICAL. Incident escalated to human operator. ' . $result['message'],
            true
        );
    }

    private function buildResponse(array $incident, string $action, string $result, string $notes, bool $escalated): array
    {
        return [
            'incident_id'   => $incident['incident_id'],
            'incident_type' => $incident['type'],
            'action_taken'  => $action,
            'timestamp'     => now()->toIso8601String(),
            'result'        => $result,
            'notes'         => $notes,
            'escalated'     => $escalated,
            'policy'        => $this->policies[$incident['type']] ?? null,
        ];
    }

    private function displayResponse(array $response): void
    {
        $icon = match($response['result']) {
            'SUCCESS'   => '✅',
            'ESCALATED' => '🚨',
            default     => '❌',
        };
        $this->line("  {$icon} [{$response['incident_type']}] → {$response['action_taken']} → {$response['result']}");
        $this->line("     {$response['notes']}");
    }

    private function saveResponses(array $responses): void
    {
        $dir = storage_path('aiops');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/responses.json';
        $existing = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        $merged   = array_merge($existing, $responses);

        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT));
    }
}