<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IncidentManager
{
    protected string $storagePath = 'aiops/incidents.json';

    // ─── Save incidents to storage ────────────────────────────────────────────

    public function saveIncidents(array $incidents): array
    {
        if (empty($incidents)) {
            return [];
        }

        $existing = $this->loadIncidents();
        $saved    = [];

        foreach ($incidents as $incident) {
            // Check for duplicate incident type within last 5 minutes
            if ($this->isDuplicate($existing, $incident)) {
                Log::info('[IncidentManager] Suppressed duplicate incident: ' . $incident['incident_type']);
                continue;
            }

            $existing[] = $incident;
            $saved[]    = $incident;
            Log::warning('[IncidentManager] Incident saved: ' . $incident['incident_id']);
        }

        $this->writeIncidents($existing);
        return $saved;
    }

    // ─── Load all incidents from storage ─────────────────────────────────────

    public function loadIncidents(): array
    {
        if (!Storage::exists($this->storagePath)) {
            return [];
        }
        $json = Storage::get($this->storagePath);
        return json_decode($json, true) ?? [];
    }

    // ─── Get open incidents ───────────────────────────────────────────────────

    public function getOpenIncidents(): array
    {
        return array_filter(
            $this->loadIncidents(),
            fn($i) => $i['status'] === 'OPEN'
        );
    }

    // ─── Resolve an incident by ID ────────────────────────────────────────────

    public function resolveIncident(string $incidentId): bool
    {
        $incidents = $this->loadIncidents();
        $found     = false;

        foreach ($incidents as &$incident) {
            if ($incident['incident_id'] === $incidentId) {
                $incident['status']      = 'RESOLVED';
                $incident['resolved_at'] = now()->toISOString();
                $found = true;
            }
        }

        if ($found) {
            $this->writeIncidents($incidents);
        }

        return $found;
    }

    // ─── Check if a duplicate incident exists in last 5 minutes ──────────────

    protected function isDuplicate(array $existing, array $newIncident): bool
    {
        $fiveMinutesAgo = now()->subMinutes(5);

        foreach ($existing as $incident) {
            if (
                $incident['incident_type'] === $newIncident['incident_type'] &&
                $incident['status']        === 'OPEN' &&
                $incident['affected_service'] === $newIncident['affected_service']
            ) {
                $detectedAt = \Carbon\Carbon::parse($incident['detected_at']);
                if ($detectedAt->greaterThan($fiveMinutesAgo)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ─── Write incidents to storage ───────────────────────────────────────────

    protected function writeIncidents(array $incidents): void
    {
        // Ensure directory exists
        Storage::makeDirectory('aiops');
        Storage::put($this->storagePath, json_encode($incidents, JSON_PRETTY_PRINT));
    }

    // ─── Get incident count summary ───────────────────────────────────────────

    public function getSummary(): array
    {
        $incidents = $this->loadIncidents();
        $open      = array_filter($incidents, fn($i) => $i['status'] === 'OPEN');
        $resolved  = array_filter($incidents, fn($i) => $i['status'] === 'RESOLVED');

        return [
            'total'    => count($incidents),
            'open'     => count($open),
            'resolved' => count($resolved),
        ];
    }
}
