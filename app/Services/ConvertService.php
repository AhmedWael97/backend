<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Convert.com (Convert Experiences) REST API.
 *
 * Convert owns variant assignment + the A/B stats engine; EYE pulls the
 * experiences/reports here and overlays its own revenue-per-variant (see
 * ExperimentController::convert*). Mirrors GrowthBookService.
 *
 * All methods degrade gracefully: if Convert isn't configured or a call fails,
 * they return null/[] so the Studio can show a "connect Convert" state.
 *
 * NOTE: Convert's API shapes vary by account/version. We read defensively and
 * normalise to { id, name, key, status, variations[] } for the UI.
 */
class ConvertService
{
    private ?string $host;
    private ?string $accountId;
    private ?string $appId;
    private ?string $key;

    public function __construct()
    {
        $this->host = rtrim((string) config('services.convert.api_host'), '/') ?: null;
        $this->accountId = config('services.convert.account_id') ?: null;
        $this->appId = config('services.convert.application_id') ?: null;
        $this->key = config('services.convert.api_key') ?: null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->host) && !empty($this->key) && !empty($this->accountId);
    }

    /** List experiences (experiments) for the configured account. */
    public function listExperiments(): array
    {
        $body = $this->get("/accounts/{$this->accountId}/experiences");
        $items = $body['experiences'] ?? $body['data'] ?? $body['items'] ?? [];
        return is_array($items) ? $items : [];
    }

    public function experiment(string $id): ?array
    {
        $body = $this->get("/accounts/{$this->accountId}/experiences/{$id}");
        return $body['experience'] ?? $body['data'] ?? $body;
    }

    /** Convert report/results for an experience. */
    public function results(string $id): ?array
    {
        $body = $this->get("/accounts/{$this->accountId}/experiences/{$id}/report");
        return $body['report'] ?? $body['data'] ?? $body;
    }

    private function get(string $path): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        try {
            $req = Http::acceptJson()->timeout(12);
            // Convert supports an application id + API key pair; send both ways
            // it commonly accepts (bearer + header) so config differences still work.
            $req = $req->withToken($this->key)->withHeaders(array_filter([
                'X-Convert-Application-Id' => $this->appId,
            ]));
            $res = $req->get($this->host . $path);
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
