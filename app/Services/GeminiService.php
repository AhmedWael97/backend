<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Google Gemini client for on-demand, plain-English recommendations from
 * raw metrics. Degrades gracefully (returns null) when unconfigured/unreachable
 * so callers can fall back to rule-based advice.
 */
class GeminiService
{
    private string $key;
    private string $model;

    public function __construct()
    {
        $this->key = (string) config('services.gemini.key');
        $this->model = (string) config('services.gemini.model', 'gemini-2.0-flash');
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    /** Return generated text, or null on any failure. */
    public function generate(string $prompt): ?string
    {
        if (!$this->configured()) {
            return null;
        }
        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
            $res = Http::timeout(20)
                ->withHeaders(['x-goog-api-key' => $this->key])
                ->post($url . '?key=' . urlencode($this->key), [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 800],
                ]);
            if ($res->failed()) {
                Log::warning('Gemini failed', ['status' => $res->status(), 'body' => substr($res->body(), 0, 300)]);
                return null;
            }
            $text = $res->json('candidates.0.content.parts.0.text');
            return is_string($text) && $text !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('Gemini exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
