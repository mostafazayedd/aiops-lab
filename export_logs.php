<?php

$logFile = __DIR__ . '/storage/logs/aiops.log';
$outputFile = __DIR__ . '/logs.json';

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$entries = [];

foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if ($decoded && isset($decoded['context'])) {
        $entries[] = $decoded['context'];
    }
}

file_put_contents($outputFile, json_encode($entries, JSON_PRETTY_PRINT));
echo "Exported " . count($entries) . " entries to logs.json\n";