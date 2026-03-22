<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrometheusClient
{
    protected string $baseUrl;
    protected array $endpoints = [
        '/api/normal',
        '/api/slow',
        '/api/db',
        '/api/error',
        '/api/validate',
    ];

    public function __construct()
    {
        $this->baseUrl = config('services.prometheus.url', 'http://localhost:9090');
    }

    public function query(string $promql): mixed
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/api/v1/query', [
                'query' => $promql,
            ]);
            if ($response->failed()) return null;
            $data = $response->json();
            if ($data['status'] !== 'success') return null;
            return $data['data']['result'] ?? [];
        } catch (\Exception $e) {
            Log::error('[PrometheusClient] ' . $e->getMessage());
            return null;
        }
    }

    public function getRequestRates(): array
    {
        return $this->groupByPath($this->query('rate(app_http_requests_total[2m])'));
    }

    public function getErrorRates(): array
    {
        return $this->groupByPath($this->query('rate(app_http_requests_total{status=~"4..|5.."}[2m])'));
    }

    public function getLatencyPercentiles(): array
    {
        $percentiles = ['0.5' => 'p50', '0.95' => 'p95', '0.99' => 'p99'];
        $output = [];
        foreach ($percentiles as $quantile => $label) {
            $grouped = $this->groupByPath(
                $this->query("histogram_quantile({$quantile}, rate(app_http_request_duration_seconds_bucket[2m]))")
            );
            foreach ($grouped as $path => $value) {
                $output[$path][$label] = $value;
            }
        }
        return $output;
    }

    public function getErrorCategoryCounters(): array
    {
        $result = $this->query('sum by (category) (rate(app_http_errors_total[2m]))');
        $output = [];
        if (!$result) return $output;
        foreach ($result as $item) {
            $category = $item['metric']['category'] ?? 'unknown';
            $output[$category] = (float)($item['value'][1] ?? 0);
        }
        return $output;
    }

    public function getAllMetrics(): array
    {
        return [
            'request_rates'       => $this->getRequestRates(),
            'error_rates'         => $this->getErrorRates(),
            'latency_percentiles' => $this->getLatencyPercentiles(),
            'error_categories'    => $this->getErrorCategoryCounters(),
            'collected_at'        => now()->toISOString(),
        ];
    }

    protected function groupByPath(?array $result): array
    {
        $output = [];
        if (!$result) return $output;
        foreach ($result as $item) {
            $path = $item['metric']['path'] ?? 'unknown';
            $output[$path] = ($output[$path] ?? 0) + (float)($item['value'][1] ?? 0);
        }
        return $output;
    }

    public function getEndpoints(): array
    {
        return $this->endpoints;
    }
}
