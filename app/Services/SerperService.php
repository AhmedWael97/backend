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
        return $this->search($query, $num)['organic'] ?? [];
    }

    /**
     * Real "people also search for" phrases — genuine adjacent search intent,
     * not guessed. Good raw material for content-idea discovery.
     *
     * @return array<int, string>
     */
    public function relatedSearches(string $query): array
    {
        $related = $this->search($query)['relatedSearches'] ?? [];
        return array_values(array_filter(array_map(fn ($r) => (string) ($r['query'] ?? ''), $related)));
    }

    /**
     * Real "People Also Ask" questions for a query — direct evidence of what
     * people actually want answered, ideal blog-post angles.
     *
     * @return array<int, string>
     */
    public function peopleAlsoAsk(string $query): array
    {
        $paa = $this->search($query)['peopleAlsoAsk'] ?? [];
        return array_values(array_filter(array_map(fn ($p) => (string) ($p['question'] ?? ''), $paa)));
    }

    private function search(string $query, int $num = 10): array
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

        return $response->json() ?? [];
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
