<?php

$logFile = __DIR__ . '/storage/logs/aiops.log';
$anomalyStart = '2026-03-09T22:52:18+00:00';
$anomalyEnd   = '2026-03-09T23:06:39+00:00';

$endpoints = [
    ['path' => 'api/normal',   'route' => 'api.normal',   'method' => 'GET',  'weight' => 70],
    ['path' => 'api/slow',     'route' => 'api.slow',     'method' => 'GET',  'weight' => 15],
    ['path' => 'api/error',    'route' => 'api.error',    'method' => 'GET',  'weight' => 5],
    ['path' => 'api/random',   'route' => 'api.random',   'method' => 'GET',  'weight' => 3],
    ['path' => 'api/db',       'route' => 'api.db',       'method' => 'GET',  'weight' => 3],
    ['path' => 'api/validate', 'route' => 'api.validate', 'method' => 'POST', 'weight' => 2],
    ['path' => 'api/db-fail',  'route' => 'api.db',       'method' => 'GET',  'weight' => 1, 'fail' => true],
];

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    'python-requests/2.32.5',
    'curl/7.68.0',
];

$weighted = [];
foreach ($endpoints as $ep) {
    for ($i = 0; $i < $ep['weight']; $i++) {
        $weighted[] = $ep;
    }
}

$lines = [];
$baseStart = strtotime('2026-03-09T21:00:00+00:00');
$totalRequests = 1600;

for ($i = 0; $i < $totalRequests; $i++) {
    $ep = $weighted[array_rand($weighted)];
    $timestamp = date('c', $baseStart + ($i * 3));
    $inAnomaly = ($timestamp >= $anomalyStart && $timestamp <= $anomalyEnd);

    if ($inAnomaly && rand(1, 100) <= 35) {
        $ep = ['path' => 'api/error', 'route' => 'api.error', 'method' => 'GET', 'weight' => 35];
    }

    $statusCode = 200;
    $errorCategory = null;
    $severity = 'info';
    $latency = rand(10, 80);

    if ($ep['path'] === 'api/error') {
        $statusCode = 500;
        $errorCategory = 'SYSTEM_ERROR';
        $severity = 'error';
        $latency = rand(10, 50);
    } elseif ($ep['path'] === 'api/slow') {
        $latency = rand(1000, 3000);
    } elseif (isset($ep['fail'])) {
        $statusCode = 500;
        $errorCategory = 'DATABASE_ERROR';
        $severity = 'error';
    } elseif ($ep['path'] === 'api/validate' && rand(1, 2) === 1) {
        $statusCode = 422;
        $errorCategory = 'VALIDATION_ERROR';
        $severity = 'error';
    } elseif ($ep['path'] === 'api/slow' && rand(1, 10) === 1) {
        $latency = rand(5000, 7000);
        $errorCategory = 'TIMEOUT_ERROR';
        $severity = 'error';
    }

    $lines[] = json_encode([
        'message' => 'request',
        'context' => [
            'correlation_id'     => sprintf('%08x-%04x-%04x-%04x-%012x', rand(), rand(), rand(), rand(), rand()),
            'method'             => $ep['method'],
            'path'               => $ep['path'],
            'route_name'         => $ep['route'],
            'status_code'        => $statusCode,
            'latency_ms'         => $latency,
            'error_category'     => $errorCategory,
            'severity'           => $severity,
            'client_ip'          => '127.0.0.1',
            'user_agent'         => $userAgents[array_rand($userAgents)],
            'query'              => null,
            'payload_size_bytes' => $ep['method'] === 'POST' ? rand(20, 50) : 0,
            'response_size_bytes'=> rand(40, 300),
            'build_version'      => '1.0.0',
            'host'               => 'DESKTOP-PVICRC3',
            'timestamp'          => $timestamp,
        ],
        'level'      => $severity === 'error' ? 400 : 200,
        'level_name' => strtoupper($severity),
        'channel'    => 'local',
        'datetime'   => $timestamp,
        'extra'      => [],
    ]);
}

file_put_contents($logFile, implode("\n", $lines) . "\n");
echo "Generated " . count($lines) . " log entries in aiops.log\n";