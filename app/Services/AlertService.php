<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AlertService
{
    protected string $alertLogPath = 'aiops/alerts.json';
    protected array $sentAlerts = [];

    // ─── Process and emit alerts for new incidents ────────────────────────────

    public function processIncidents(array $savedIncidents): void
    {
        foreach ($savedIncidents as $incident) {
            $this->emitAlert($incident);
        }
    }

    // ─── Emit a single alert ──────────────────────────────────────────────────

    public function emitAlert(array $incident): void
    {
        $alert = $this->buildAlert($incident);

        // Console alert
        $this->consoleAlert($alert);

        // JSON alert (written to file)
        $this->jsonAlert($alert);

        Log::channel('stack')->info('[AlertService] Alert emitted: ' . $alert['incident_id']);
    }

    // ─── Build alert payload ──────────────────────────────────────────────────

    protected function buildAlert(array $incident): array
    {
        return [
            'incident_id'   => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'severity'      => $incident['severity'],
            'timestamp'     => now()->toISOString(),
            'summary'       => $incident['summary'],
        ];
    }

    // ─── Console alert ────────────────────────────────────────────────────────

    protected function consoleAlert(array $alert): void
    {
        $line = str_repeat('=', 60);
        $severityIcon = match($alert['severity']) {
            'CRITICAL' => '🔴',
            'HIGH'     => '🟠',
            'WARNING'  => '🟡',
            default    => '🔵',
        };

        echo "\n{$line}\n";
        echo "{$severityIcon} AIOPS ALERT [{$alert['severity']}]\n";
        echo "Incident ID : {$alert['incident_id']}\n";
        echo "Type        : {$alert['incident_type']}\n";
        echo "Time        : {$alert['timestamp']}\n";
        echo "Summary     : {$alert['summary']}\n";
        echo "{$line}\n\n";
    }

    // ─── JSON alert (append to alerts.json) ──────────────────────────────────

    protected function jsonAlert(array $alert): void
    {
        $existing   = $this->loadAlerts();
        $existing[] = $alert;
        Storage::put($this->alertLogPath, json_encode($existing, JSON_PRETTY_PRINT));
    }

    // ─── Load existing alerts ─────────────────────────────────────────────────

    public function loadAlerts(): array
    {
        if (!Storage::exists($this->alertLogPath)) {
            return [];
        }
        return json_decode(Storage::get($this->alertLogPath), true) ?? [];
    }

    // ─── Get alert count ──────────────────────────────────────────────────────

    public function getAlertCount(): int
    {
        return count($this->loadAlerts());
    }
}
