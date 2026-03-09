<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Services\MetricsService;

class TelemetryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip /metrics endpoint
        if ($request->is('api/metrics')) {
            return $next($request);
        }

        // Correlation ID
        $correlationId = $request->header('X-Request-Id') ?? Str::uuid()->toString();

        // Start timer
        $startTime = microtime(true);

        // Process request
        $response = $next($request);

        // Latency
        $latencyMs = round((microtime(true) - $startTime) * 1000);
        $latencySeconds = (microtime(true) - $startTime);

        // Status
        $statusCode = $response->getStatusCode();

        // Determine error category
        $errorCategory = null;

        if ($latencyMs > 4000) {
            $errorCategory = 'TIMEOUT_ERROR';
        } elseif ($statusCode >= 500) {
            $errorCategory = 'SYSTEM_ERROR';
        } elseif ($statusCode >= 400) {
            $errorCategory = 'VALIDATION_ERROR';
        }

        // Severity
        $severity = $statusCode >= 400 ? 'error' : 'info';

        // Path and method
        $method = $request->method();
        $path = '/' . $request->path();

        // Record Prometheus metrics
        MetricsService::incrementRequestCounter($method, $path, $statusCode);
        MetricsService::observeRequestDuration($method, $path, $latencySeconds);

        if ($errorCategory !== null) {
            MetricsService::incrementErrorCounter($method, $path, $errorCategory);
        }

        // Response size
        $responseSize = strlen($response->getContent());

        // Log record
        $logData = [
            'correlation_id'       => $correlationId,
            'method'               => $method,
            'path'                 => $request->path(),
            'route_name'           => optional($request->route())->getName() ?? 'unknown',
            'status_code'          => $statusCode,
            'latency_ms'           => $latencyMs,
            'error_category'       => $errorCategory,
            'severity'             => $severity,
            'client_ip'            => $request->ip(),
            'user_agent'           => $request->userAgent() ?? null,
            'query'                => $request->getQueryString() ?? null,
            'payload_size_bytes'   => $request->header('Content-Length') ?? 0,
            'response_size_bytes'  => $responseSize,
            'build_version'        => env('BUILD_VERSION', '1.0.0'),
            'host'                 => gethostname(),
            'timestamp'            => now()->toIso8601String(),
        ];

        if ($severity === 'error') {
            Log::channel('aiops')->error('request', $logData);
        } else {
            Log::channel('aiops')->info('request', $logData);
        }

        // Attach correlation ID to response
        $response->headers->set('X-Request-Id', $correlationId);

        return $response;
    }
}