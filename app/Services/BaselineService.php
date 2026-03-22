<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BaselineService
{
    protected PrometheusClient $prometheus;
    protected string $storagePath = 'aiops/baselines.json';
    protected int $maxObservations = 50;

    protected array $endpoints = [
        '/api/normal',
        '/api/slow',
        '/api/db',
        '/api/error',
        '/api/validate',
    ];

    public function __construct(PrometheusClient $prometheus)
    {
        $this->prometheus = $prometheus;
    }

    // ─── Record a new observation snapshot ───────────────────────────────────

    public function recordObservation(): void
    {
        $metrics = $this->prometheus->getAllMetrics();
        $observations = $this->loadObservations();
        $timestamp = now()->toISOString();

        foreach ($this->endpoints as $endpoint) {
            $requestRate = $metrics['request_rates'][$endpoint] ?? null;
            $errorRate   = $metrics['error_rates'][$endpoint] ?? null;
            $p50         = $metrics['latency_percentiles'][$endpoint]['p50'] ?? null;
            $p95         = $metrics['latency_percentiles'][$endpoint]['p95'] ?? null;
            $p99         = $metrics['latency_percentiles'][$endpoint]['p99'] ?? null;

            // Only record if we have at least request rate data
            if ($requestRate === null) continue;

            $observations[$endpoint][] = [
                'timestamp'    => $timestamp,
                'request_rate' => $requestRate,
                'error_rate'   => $errorRate ?? 0.0,
                'p50'          => $p50 ?? 0.0,
                'p95'          => $p95 ?? 0.0,
                'p99'          => $p99 ?? 0.0,
            ];

            // Keep only the last N observations
            $observations[$endpoint] = array_slice(
                $observations[$endpoint],
                -$this->maxObservations
            );
        }

        $this->saveObservations($observations);
        Log::info('[BaselineService] Observation recorded at ' . $timestamp);
    }

    // ─── Compute baselines from stored observations ───────────────────────────

    public function computeBaselines(): array
    {
        $observations = $this->loadObservations();
        $baselines = [];

        foreach ($this->endpoints as $endpoint) {
            $data = $observations[$endpoint] ?? [];

            if (count($data) < 3) {
                $baselines[$endpoint] = null; // Not enough data yet
                continue;
            }

            $baselines[$endpoint] = [
                'avg_request_rate' => $this->average(array_column($data, 'request_rate')),
                'avg_error_rate'   => $this->average(array_column($data, 'error_rate')),
                'avg_p50'          => $this->average(array_column($data, 'p50')),
                'avg_p95'          => $this->average(array_column($data, 'p95')),
                'avg_p99'          => $this->average(array_column($data, 'p99')),
                'observation_count' => count($data),
                'computed_at'      => now()->toISOString(),
            ];
        }

        return $baselines;
    }

    // ─── Get baseline for a single endpoint ──────────────────────────────────

    public function getBaseline(string $endpoint): ?array
    {
        $baselines = $this->computeBaselines();
        return $baselines[$endpoint] ?? null;
    }

    // ─── Check if we have enough data to start detecting ─────────────────────

    public function isReady(): bool
    {
        $observations = $this->loadObservations();
        foreach ($this->endpoints as $endpoint) {
            if (count($observations[$endpoint] ?? []) >= 3) {
                return true;
            }
        }
        return false;
    }

    // ─── Load observations from storage ──────────────────────────────────────

    public function loadObservations(): array
    {
        if (!Storage::exists($this->storagePath)) {
            return [];
        }
        $json = Storage::get($this->storagePath);
        return json_decode($json, true) ?? [];
    }

    // ─── Save observations to storage ────────────────────────────────────────

    protected function saveObservations(array $observations): void
    {
        Storage::put($this->storagePath, json_encode($observations, JSON_PRETTY_PRINT));
    }

    // ─── Helper: compute average of an array ─────────────────────────────────

    protected function average(array $values): float
    {
        $values = array_filter($values, fn($v) => $v !== null);
        if (count($values) === 0) return 0.0;
        return array_sum($values) / count($values);
    }
}
