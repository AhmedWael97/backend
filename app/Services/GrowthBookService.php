<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the GrowthBook REST API (self-hosted or cloud).
 *
 * GrowthBook owns experiment assignment + the rigorous stats engine (it can use
 * EYE's ClickHouse as its data source). EYE pulls the experiment list/results
 * here and overlays its own revenue-per-variant (see ExperimentController).
 *
 * All methods degrade gracefully: if GrowthBook isn't configured or the call
 * fails, they return null/[] so the UI can show a "connect GrowthBook" state.
 */
class GrowthBookService
{
    private ?string $host;
    private ?string $key;

    public function __construct()
    {
        $this->host = rtrim((string) config('services.growthbook.api_host'), '/') ?: null;
        $this->key = config('services.growthbook.api_key') ?: null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->host) && !empty($this->key);
    }

    /** List experiments (optionally filtered by GrowthBook project id). */
    public function listExperiments(?string $projectId = null): array
    {
        $query = $projectId ? ['project' => $projectId] : [];
        $body = $this->get('/api/v1/experiments', $query);
        return $body['experiments'] ?? [];
    }

    /** Fetch a single experiment definition. */
    public function experiment(string $id): ?array
    {
        $body = $this->get("/api/v1/experiments/{$id}");
        return $body['experiment'] ?? null;
    }

    /** Fetch the computed results/analysis for an experiment. */
    public function results(string $id): ?array
    {
        $body = $this->get("/api/v1/experiments/{$id}/results");
        return $body['result'] ?? $body['results'] ?? $body;
    }

    private function get(string $path, array $query = []): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        try {
            $res = Http::withToken($this->key)
                ->acceptJson()
                ->timeout(12)
                ->get($this->host . $path, $query);
            if (!$res->successful()) {
                return null;
            }
            return $res->json();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }
}
