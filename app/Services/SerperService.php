<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Thin client for the Serper.dev Google Search API — real organic SERP results. */
class SerperService
{
    private string $key;

    public function __construct()
    {
        $this->key = (string) config('services.serper.key', '');
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    /**
     * @return array<int, array{position?: int, link?: string, title?: string}>
     */
    public function organicResults(string $query, int $num = 100): array
    {
        $response = Http::withHeaders([
            'X-API-KEY' => $this->key,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://google.serper.dev/search', [
                    'q' => $query,
                    'num' => $num,
                ]);

        if ($response->failed()) {
            Log::error('Serper API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException("Serper API error: {$response->status()}");
        }

        return $response->json('organic') ?? [];
    }

    /** 1-based rank of the first result whose link host matches $domain, or null if not found in the given results. */
    public function findPosition(array $organic, string $domain): ?int
    {
        $target = preg_replace('/^www\./', '', strtolower($domain));
        foreach ($organic as $r) {
            $host = strtolower((string) (parse_url($r['link'] ?? '', PHP_URL_HOST) ?? ''));
            $host = preg_replace('/^www\./', '', $host);
            if ($host !== '' && $host === $target) {
                $pos = (int) ($r['position'] ?? 0);
                return $pos > 0 ? $pos : null;
            }
        }
        return null;
    }
}
