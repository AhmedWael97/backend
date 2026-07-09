<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic text generation with automatic failover.
 *
 * Tries each configured provider in order (default: anthropic → gemini → openai),
 * skipping ones without a key and falling through on any failure — so a dead key,
 * a zeroed free-tier quota, or an outage at one vendor never takes the feature down.
 */
class AiTextService
{
    /** Status of the last attempt per provider, for diagnostics. */
    public array $attempts = [];

    /** True when every configured provider refused with a quota/rate-limit error. */
    public bool $quotaExhausted = false;

    /** @return array{text: string, provider: string}|null */
    public function generate(string $prompt, int $maxTokens = 900): ?array
    {
        $this->attempts = [];
        $this->quotaExhausted = false;
        $anyConfigured = false;
        $allQuota = true;

        foreach ($this->order() as $provider) {
            if (!$this->configured($provider)) {
                continue;
            }
            $anyConfigured = true;

            try {
                [$text, $status] = $this->call($provider, $prompt, $maxTokens);
            } catch (\Throwable $e) {
                Log::warning('AI provider exception', ['provider' => $provider, 'msg' => $e->getMessage()]);
                $this->attempts[$provider] = 'exception';
                $allQuota = false;
                continue;
            }

            if ($text !== null && $text !== '') {
                $this->attempts[$provider] = 'ok';

                return ['text' => $text, 'provider' => $provider];
            }

            $this->attempts[$provider] = (string) $status;
            if (!in_array($status, [429, 402], true)) {
                $allQuota = false;
            }
        }

        $this->quotaExhausted = $anyConfigured && $allQuota;

        return null;
    }

    /** @return string[] */
    private function order(): array
    {
        $raw = (string) config('services.ai.order', 'anthropic,gemini,openai');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function configured(string $provider): bool
    {
        return match ($provider) {
            'anthropic' => (string) config('services.anthropic.key') !== '',
            'gemini' => (string) config('services.gemini.key') !== '',
            'openai' => (string) config('services.openai.key') !== '',
            default => false,
        };
    }

    /** @return array{0: ?string, 1: int} [text, httpStatus] */
    private function call(string $provider, string $prompt, int $maxTokens): array
    {
        return match ($provider) {
            'anthropic' => $this->anthropic($prompt, $maxTokens),
            'gemini' => $this->gemini($prompt, $maxTokens),
            'openai' => $this->openai($prompt, $maxTokens),
            default => [null, 0],
        };
    }

    private function anthropic(string $prompt, int $maxTokens): array
    {
        $res = Http::timeout(30)
            ->withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
                'max_tokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if ($res->failed()) {
            $this->logFail('anthropic', $res->status(), $res->body());

            return [null, $res->status()];
        }

        return [$res->json('content.0.text'), 200];
    }

    private function gemini(string $prompt, int $maxTokens): array
    {
        $key = (string) config('services.gemini.key');
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');

        $res = Http::timeout(30)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key),
            [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => $maxTokens],
            ]
        );

        if ($res->failed()) {
            $this->logFail('gemini', $res->status(), $res->body());

            return [null, $res->status()];
        }

        return [$res->json('candidates.0.content.parts.0.text'), 200];
    }

    private function openai(string $prompt, int $maxTokens): array
    {
        $res = Http::timeout(30)
            ->withToken((string) config('services.openai.key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'max_tokens' => $maxTokens,
                'temperature' => 0.4,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if ($res->failed()) {
            $this->logFail('openai', $res->status(), $res->body());

            return [null, $res->status()];
        }

        return [$res->json('choices.0.message.content'), 200];
    }

    private function logFail(string $provider, int $status, string $body): void
    {
        Log::warning('AI provider failed', [
            'provider' => $provider,
            'status' => $status,
            'body' => substr($body, 0, 200),
        ]);
    }
}
