<?php

namespace App\Services;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\RenderTextFormat;

class MetricsService
{
    private static string $metricsFile;

    private static function getMetricsFile(): string
    {
        return storage_path('logs/metrics.json');
    }

    private static function loadMetrics(): array
    {
        $file = self::getMetricsFile();
        if (!file_exists($file)) {
            return ['counters' => [], 'histograms' => []];
        }
        $data = json_decode(file_get_contents($file), true);
        return $data ?? ['counters' => [], 'histograms' => []];
    }

    private static function saveMetrics(array $data): void
    {
        file_put_contents(self::getMetricsFile(), json_encode($data), LOCK_EX);
    }

    public static function incrementRequestCounter(string $method, string $path, int $status): void
    {
        $data = self::loadMetrics();
        $key = "http_requests_total|{$method}|{$path}|{$status}";
        $data['counters'][$key] = ($data['counters'][$key] ?? 0) + 1;
        self::saveMetrics($data);
    }

    public static function incrementErrorCounter(string $method, string $path, string $errorCategory): void
    {
        $data = self::loadMetrics();
        $key = "http_errors_total|{$method}|{$path}|{$errorCategory}";
        $data['counters'][$key] = ($data['counters'][$key] ?? 0) + 1;
        self::saveMetrics($data);
    }

    public static function observeRequestDuration(string $method, string $path, float $durationSeconds): void
    {
        $data = self::loadMetrics();
        $key = "{$method}|{$path}";
        $buckets = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

        if (!isset($data['histograms'][$key])) {
            $data['histograms'][$key] = ['sum' => 0, 'count' => 0, 'buckets' => []];
            foreach ($buckets as $b) {
                $data['histograms'][$key]['buckets'][(string)$b] = 0;
            }
        }

        $data['histograms'][$key]['sum'] += $durationSeconds;
        $data['histograms'][$key]['count'] += 1;

        foreach ($buckets as $b) {
            if ($durationSeconds <= $b) {
                $data['histograms'][$key]['buckets'][(string)$b] += 1;
            }
        }

        self::saveMetrics($data);
    }

    public static function renderMetrics(): string
    {
        $data = self::loadMetrics();
        $output = '';

        // Counters
        $requestGroups = [];
        $errorGroups = [];

        foreach ($data['counters'] as $key => $value) {
            $parts = explode('|', $key);
            if ($parts[0] === 'http_requests_total') {
                $requestGroups[] = "app_http_requests_total{method=\"{$parts[1]}\",path=\"{$parts[2]}\",status=\"{$parts[3]}\"} {$value}";
            } elseif ($parts[0] === 'http_errors_total') {
                $errorGroups[] = "app_http_errors_total{method=\"{$parts[1]}\",path=\"{$parts[2]}\",error_category=\"{$parts[3]}\"} {$value}";
            }
        }

        if (!empty($requestGroups)) {
            $output .= "# HELP app_http_requests_total Total HTTP requests\n";
            $output .= "# TYPE app_http_requests_total counter\n";
            $output .= implode("\n", $requestGroups) . "\n";
        }

        if (!empty($errorGroups)) {
            $output .= "# HELP app_http_errors_total Total HTTP errors\n";
            $output .= "# TYPE app_http_errors_total counter\n";
            $output .= implode("\n", $errorGroups) . "\n";
        }

        // Histograms
        if (!empty($data['histograms'])) {
            $output .= "# HELP app_http_request_duration_seconds HTTP request duration\n";
            $output .= "# TYPE app_http_request_duration_seconds histogram\n";

            foreach ($data['histograms'] as $key => $hist) {
                [$method, $path] = explode('|', $key, 2);
                $cumulative = 0;
                foreach ($hist['buckets'] as $le => $count) {
    $output .= "app_http_request_duration_seconds_bucket{method=\"{$method}\",path=\"{$path}\",le=\"{$le}\"} {$count}\n";
}
                $output .= "app_http_request_duration_seconds_bucket{method=\"{$method}\",path=\"{$path}\",le=\"+Inf\"} {$hist['count']}\n";
                $output .= "app_http_request_duration_seconds_sum{method=\"{$method}\",path=\"{$path}\"} {$hist['sum']}\n";
                $output .= "app_http_request_duration_seconds_count{method=\"{$method}\",path=\"{$path}\"} {$hist['count']}\n";
            }
        }

        return $output ?: "# no metrics yet\n";
    }
}