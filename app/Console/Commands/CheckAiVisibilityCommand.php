<?php

namespace App\Console\Commands;

use App\Models\AiVisibilityCheck;
use App\Services\AnthropicService;
use Illuminate\Console\Command;

/**
 * AI-visibility tracking: asks a real AI assistant genuine buyer-intent
 * questions (never mentioning EYE ourselves — that would defeat the point)
 * and records whether EYE comes up organically in the answer. Same idea as
 * classic SEO rank tracking, but for AI answer engines instead of Google.
 *
 * Currently checks Claude only, since that's the provider we already have a
 * key for. Checking ChatGPT/Perplexity too would need their own API keys
 * added to config/services.php first — this command is written so adding
 * another engine later is just another AnthropicService-shaped client and
 * one more loop iteration, not a rewrite.
 */
class CheckAiVisibilityCommand extends Command
{
    protected $signature = 'eye:check-ai-visibility';

    protected $description = 'Ask an AI assistant real buyer-intent questions and record whether EYE gets mentioned organically.';

    /** Real questions a prospective customer would actually ask — no mention of EYE, so the answer is genuine. */
    private const QUERIES = [
        "What's the best Hotjar alternative?",
        "What's a good privacy-first, cookieless website analytics tool?",
        'Best session replay software for websites?',
        'Recommend a good no-code A/B testing tool for a small business website.',
        "What's a good Google Analytics alternative?",
        'Best heatmap tool for websites?',
    ];

    private const SYSTEM = 'You are a helpful assistant. Answer the question directly and practically, recommending specific real tools/products by name where relevant, as if a business owner asked you for advice.';

    public function handle(AnthropicService $ai): int
    {
        $ok = 0;

        foreach (self::QUERIES as $query) {
            try {
                $answer = $ai->completeText(self::SYSTEM, $query, 1024);
                $mentioned = str_contains(strtolower($answer), 'eye analytics')
                    || str_contains(strtolower($answer), 'eye-analysis.online');

                AiVisibilityCheck::create([
                    'query' => $query,
                    'engine' => 'claude',
                    'mentioned' => $mentioned,
                    'answer' => $answer,
                    'checked_at' => now(),
                ]);
                $ok++;
            } catch (\Throwable $e) {
                report($e);
                $this->error("Failed to check '{$query}': {$e->getMessage()}");
            }
        }

        $this->info("Checked {$ok}/" . count(self::QUERIES) . ' quer' . (count(self::QUERIES) === 1 ? 'y' : 'ies') . '.');
        return self::SUCCESS;
    }
}
