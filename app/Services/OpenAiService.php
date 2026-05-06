<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Chat Completions wrapper.
 * Configured via services.openai.{key,model,base_url}.
 */
class OpenAiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key', '');
        $this->model = config('services.openai.model', 'gpt-4o');
        $this->baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
    }

    /**
     * Send a chat completion and return the decoded JSON object.
     * Forces `response_format: json_object` so the model returns valid JSON.
     *
     * @param  string $systemPrompt  Role-level instructions.
     * @param  string $userMessage   The user turn payload.
     * @param  int    $maxTokens     Max tokens in the completion (default 4096).
     * @return array                 Decoded JSON result (empty array on failure).
     *
     * @throws \RuntimeException when the API returns a non-2xx status.
     */
    public function complete(string $systemPrompt, string $userMessage, int $maxTokens = 4096): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(90)->post("{$this->baseUrl}/chat/completions", [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.3,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

        if ($response->failed()) {
            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("OpenAI API error: HTTP {$response->status()}");
        }

        $data = $response->json();
        $text = $data['choices'][0]['message']['content'] ?? '{}';

        return json_decode($text, true) ?? [];
    }

    /**
     * Multi-turn chat completion — returns the plain text reply.
     * Used for the AI Assistant chatbot where we need natural language output
     * (not JSON) and pass the full conversation history.
     *
     * @param  string $systemPrompt      Role-level instructions.
     * @param  array  $messages          Array of ['role' => 'user'|'assistant', 'content' => string].
     * @param  int    $maxTokens         Max tokens in the completion (default 1024).
     * @return array{text: string, tokens_used: int}
     *
     * @throws \RuntimeException when the API returns a non-2xx status.
     */
    public function chat(string $systemPrompt, array $messages, int $maxTokens = 1024): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $payload = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$messages,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.5,
                    'messages' => $payload,
                ]);

        if ($response->failed()) {
            Log::error('OpenAI chat error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("OpenAI API error: HTTP {$response->status()}");
        }

        $data = $response->json();

        return [
            'text' => trim($data['choices'][0]['message']['content'] ?? ''),
            'tokens_used' => $data['usage']['total_tokens'] ?? 0,
        ];
    }
}
