<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AnomalyDetector
{
    protected PrometheusClient $prometheus;
    protected BaselineService $baseline;

    const LATENCY_MULTIPLIER   = 3.0;
    const TRAFFIC_MULTIPLIER   = 2.0;
    const ERROR_RATE_THRESHOLD = 0.10;

    public function __construct(PrometheusClient $prometheus, BaselineService $baseline)
    {
        $this->prometheus = $prometheus;
        $this->baseline   = $baseline;
    }

    public function detect(): array
    {
        $metrics   = $this->prometheus->getAllMetrics();
        $baselines = $this->baseline->computeBaselines();
        $anomalies = [];

        foreach ($this->prometheus->getEndpoints() as $endpoint) {
            $base = $baselines[$endpoint] ?? null;
            if (!$base) continue;

            $currentRequestRate = $metrics['request_rates'][$endpoint] ?? 0.0;
            $currentErrorRate   = $metrics['error_rates'][$endpoint]   ?? 0.0;
            $currentP95         = $metrics['latency_percentiles'][$endpoint]['p95'] ?? 0.0;

            $endpointAnomalies = [];

            // Latency Anomaly
            if ($base['avg_p95'] > 0 && $currentP95 > self::LATENCY_MULTIPLIER * $base['avg_p95']) {
                $endpointAnomalies[] = [
                    'type'           => 'LATENCY_ANOMALY',
                    'endpoint'       => $endpoint,
                    'observed_value' => $currentP95,
                    'baseline_value' => $base['avg_p95'],
                    'threshold'      => self::LATENCY_MULTIPLIER * $base['avg_p95'],
                    'severity'       => $this->latencySeverity($currentP95, $base['avg_p95']),
                    'description'    => "p95 latency {$currentP95}s exceeds 3x baseline {$base['avg_p95']}s",
                ];
            }

            // Error Rate Anomaly
            if ($currentRequestRate > 0) {
                $errorRatio = $currentErrorRate / $currentRequestRate;
                if ($errorRatio > self::ERROR_RATE_THRESHOLD) {
                    $endpointAnomalies[] = [
                        'type'           => 'ERROR_RATE_ANOMALY',
                        'endpoint'       => $endpoint,
                        'observed_value' => round($errorRatio * 100, 2),
                        'baseline_value' => round(($base['avg_error_rate'] / max($base['avg_request_rate'], 0.0001)) * 100, 2),
                        'threshold'      => self::ERROR_RATE_THRESHOLD * 100,
                        'severity'       => $this->errorSeverity($errorRatio),
                        'description'    => "Error rate " . round($errorRatio * 100, 2) . "% exceeds 10% threshold on {$endpoint}",
                    ];
                }
            }

            // Traffic Anomaly
            if ($base['avg_request_rate'] > 0 && $currentRequestRate > self::TRAFFIC_MULTIPLIER * $base['avg_request_rate']) {
                $endpointAnomalies[] = [
                    'type'           => 'TRAFFIC_ANOMALY',
                    'endpoint'       => $endpoint,
                    'observed_value' => $currentRequestRate,
                    'baseline_value' => $base['avg_request_rate'],
                    'threshold'      => self::TRAFFIC_MULTIPLIER * $base['avg_request_rate'],
                    'severity'       => 'WARNING',
                    'description'    => "Traffic spike {$currentRequestRate} req/s exceeds 2x baseline on {$endpoint}",
                ];
            }

            // Endpoint-Specific Anomaly
            if (count($endpointAnomalies) >= 2) {
                $endpointAnomalies[] = [
                    'type'           => 'ENDPOINT_SPECIFIC_ANOMALY',
                    'endpoint'       => $endpoint,
                    'observed_value' => count($endpointAnomalies),
                    'baseline_value' => 0,
                    'threshold'      => 1,
                    'severity'       => 'CRITICAL',
                    'description'    => "Multiple anomaly signals detected on {$endpoint}",
                ];
            }

            foreach ($endpointAnomalies as $anomaly) {
                $anomalies[] = $anomaly;
                Log::warning('[AnomalyDetector] ' . $anomaly['type'] . ' on ' . $endpoint);
            }
        }

        return [
            'anomalies'         => $anomalies,
            'total'             => count($anomalies),
            'detected_at'       => now()->toISOString(),
            'metrics_snapshot'  => [
                'request_rates'       => $metrics['request_rates'],
                'error_rates'         => $metrics['error_rates'],
                'latency_percentiles' => $metrics['latency_percentiles'],
            ],
            'baselines_used'    => $baselines,
        ];
    }

    protected function latencySeverity(float $current, float $baseline): string
    {
        $ratio = $baseline > 0 ? $current / $baseline : 0;
        if ($ratio >= 10) return 'CRITICAL';
        if ($ratio >= 5)  return 'HIGH';
        if ($ratio >= 3)  return 'WARNING';
        return 'INFO';
    }

    protected function errorSeverity(float $ratio): string
    {
        if ($ratio >= 0.5) return 'CRITICAL';
        if ($ratio >= 0.3) return 'HIGH';
        if ($ratio >= 0.1) return 'WARNING';
        return 'INFO';
    }
}
