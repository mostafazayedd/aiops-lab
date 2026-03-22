<?php

namespace App\Services;

class CorrelationEngine
{
    // ─── Correlate anomalies into incidents ───────────────────────────────────

    public function correlate(array $detectionResult): array
    {
        $anomalies = $detectionResult['anomalies'] ?? [];

        if (empty($anomalies)) {
            return [];
        }

        $incidents = [];

        // Group anomalies by endpoint
        $byEndpoint = [];
        foreach ($anomalies as $anomaly) {
            $byEndpoint[$anomaly['endpoint']][] = $anomaly;
        }

        // Get all anomaly types (for global correlation)
        $allTypes = array_column($anomalies, 'type');

        // ── ERROR_STORM: many endpoints with high error rates ─────────────────
        $errorAnomalies = array_filter($anomalies, fn($a) => $a['type'] === 'ERROR_RATE_ANOMALY');
        if (count($errorAnomalies) >= 2) {
            $incidents[] = $this->buildIncident(
                'ERROR_STORM',
                'CRITICAL',
                array_values($errorAnomalies),
                $detectionResult,
                'Multiple endpoints reporting critical error rates simultaneously'
            );
        }

        // ── SERVICE_DEGRADATION: mix of latency + error signals ───────────────
        $hasLatency = in_array('LATENCY_ANOMALY', $allTypes);
        $hasError   = in_array('ERROR_RATE_ANOMALY', $allTypes);
        if ($hasLatency && $hasError) {
            $signals = array_filter($anomalies, fn($a) => in_array($a['type'], ['LATENCY_ANOMALY', 'ERROR_RATE_ANOMALY']));
            $incidents[] = $this->buildIncident(
                'SERVICE_DEGRADATION',
                'HIGH',
                array_values($signals),
                $detectionResult,
                'Service is degraded: elevated latency and error rates detected together'
            );
        }

        // ── LATENCY_SPIKE: latency anomalies only ─────────────────────────────
        $latencyAnomalies = array_filter($anomalies, fn($a) => $a['type'] === 'LATENCY_ANOMALY');
        if (!empty($latencyAnomalies) && !$hasError) {
            $incidents[] = $this->buildIncident(
                'LATENCY_SPIKE',
                'HIGH',
                array_values($latencyAnomalies),
                $detectionResult,
                'Latency spike detected across one or more endpoints'
            );
        }

        // ── TRAFFIC_SURGE: traffic anomalies only ─────────────────────────────
        $trafficAnomalies = array_filter($anomalies, fn($a) => $a['type'] === 'TRAFFIC_ANOMALY');
        if (!empty($trafficAnomalies) && !$hasError && !$hasLatency) {
            $incidents[] = $this->buildIncident(
                'TRAFFIC_SURGE',
                'WARNING',
                array_values($trafficAnomalies),
                $detectionResult,
                'Unusual traffic surge detected on one or more endpoints'
            );
        }

        // ── LOCALIZED_ENDPOINT_FAILURE: single endpoint with multiple signals ──
        foreach ($byEndpoint as $endpoint => $endpointAnomalies) {
            $types = array_column($endpointAnomalies, 'type');
            if (in_array('ENDPOINT_SPECIFIC_ANOMALY', $types)) {
                $incidents[] = $this->buildIncident(
                    'LOCALIZED_ENDPOINT_FAILURE',
                    'CRITICAL',
                    $endpointAnomalies,
                    $detectionResult,
                    "Endpoint {$endpoint} is experiencing multiple simultaneous failures"
                );
            }
        }

        // Deduplicate incidents by type (keep highest severity)
        return $this->deduplicateIncidents($incidents);
    }

    // ─── Build a structured incident ──────────────────────────────────────────

    protected function buildIncident(
        string $type,
        string $severity,
        array  $signals,
        array  $detectionResult,
        string $summary
    ): array {
        $affectedEndpoints = array_values(array_unique(array_column($signals, 'endpoint')));

        $baselineValues  = [];
        $observedValues  = [];

        foreach ($signals as $signal) {
            $ep = $signal['endpoint'];
            $baselineValues[$ep][$signal['type']]  = $signal['baseline_value'];
            $observedValues[$ep][$signal['type']]  = $signal['observed_value'];
        }

        return [
            'incident_id'        => $this->generateId($type),
            'incident_type'      => $type,
            'severity'           => $severity,
            'status'             => 'OPEN',
            'detected_at'        => $detectionResult['detected_at'],
            'affected_service'   => 'laravel-aiops',
            'affected_endpoints' => $affectedEndpoints,
            'triggering_signals' => $signals,
            'baseline_values'    => $baselineValues,
            'observed_values'    => $observedValues,
            'summary'            => $summary,
        ];
    }

    // ─── Remove duplicate incident types ─────────────────────────────────────

    protected function deduplicateIncidents(array $incidents): array
    {
        $seen   = [];
        $result = [];

        foreach ($incidents as $incident) {
            $type = $incident['incident_type'];
            if (!isset($seen[$type])) {
                $seen[$type] = true;
                $result[]    = $incident;
            }
        }

        return $result;
    }

    // ─── Generate stable incident ID ─────────────────────────────────────────

    protected function generateId(string $type): string
    {
        return strtolower(str_replace('_', '-', $type)) . '-' . now()->format('YmdHis');
    }
}
