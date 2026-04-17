<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key', '');
        $this->model = config('services.anthropic.model', 'claude-3-5-sonnet-20241022');
    }

    /**
     * Send a structured prompt and return the decoded JSON response body.
     *
     * @throws \RuntimeException
     */
    public function complete(string $systemPrompt, string $userMessage, int $maxTokens = 4096): array
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/messages", [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

        if ($response->failed()) {
            Log::error('Anthropic API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("Anthropic API error: {$response->status()}");
        }

        $data = $response->json();
        $text = $data['content'][0]['text'] ?? '';

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);

        return json_decode(trim($text), true) ?? [];
    }
}
