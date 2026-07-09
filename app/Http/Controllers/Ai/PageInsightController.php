<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\AiTextService;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "AI read this page for you" banner.
 *
 * The dashboard page posts the metrics it already rendered; we ask Gemini to turn
 * them into a plain-language verdict, the reasoning, and concrete next actions —
 * so a non-technical owner can act without interpreting charts.
 *
 * Answers are cached on a hash of the data, so re-clicking the same numbers is free.
 */
class PageInsightController extends Controller
{
    private const CACHE_TTL = 21600; // 6h

    /** A site needs real traffic before the AI's verdict means anything. */
    private const MIN_VISITS = 200;

    /** Human labels for the page being analysed (keeps the prompt grounded). */
    private const PAGES = [
        'overview' => 'website traffic overview (visitors, sessions, bounce, top pages)',
        'campaigns' => 'ad campaigns (spend, revenue, ROAS, CPA per campaign)',
        'channels' => 'marketing channel mix (per-channel sessions, revenue, spend, ROAS)',
        'ltv' => 'customer lifetime value by acquisition source',
        'funnels' => 'conversion funnel steps and drop-off',
        'heatmaps' => 'click heatmap and page interaction',
        'retention' => 'visitor cohort retention over time',
        'experiments' => 'A/B test variations and conversion results',
        'leads' => 'lead pipeline',
        'seo' => 'SEO keyword rankings and site health',
        'replay' => 'session replays and friction signals',
        'portfolio' => 'multi-site portfolio performance',
    ];

    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    /** Visits (sessions) recorded for this domain in the last 90 days. */
    private function visits(int $domainId): int
    {
        try {
            $rows = $this->clickhouse->select(
                "SELECT uniq(session_id) AS c FROM events WHERE domain_id = {$domainId} AND ts >= now() - INTERVAL 90 DAY"
            );

            return (int) ($rows[0]['c'] ?? 0);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function __invoke(Request $request, AiTextService $ai, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::findOrFail($domainId);
        if (!$user->canAccessDomain($domain)) {
            return $this->error('Forbidden.', 403);
        }

        // Gate: the AI only speaks for verified owners of a site with real traffic.
        // Reasons are machine-readable so the UI can explain them in the user's language.
        if (!$user->hasVerifiedEmail()) {
            return $this->error('email_unverified', 403);
        }
        if (($visits = $this->visits($domain->id)) < self::MIN_VISITS) {
            return $this->error("not_enough_traffic:{$visits}", 403);
        }

        $validated = $request->validate([
            'page' => ['required', 'string', 'max:40'],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'data' => ['required', 'array'],
        ]);

        $page = $validated['page'];
        $locale = $validated['locale'] ?? 'en';
        $data = $validated['data'];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen($json) > 60000) {
            return $this->error('Too much data to analyse.', 422);
        }

        $key = "ai:page:{$domain->id}:{$page}:{$locale}:" . md5($json);
        if (!$request->boolean('refresh') && ($hit = Cache::get($key))) {
            return $this->success($hit + ['cached' => true]);
        }

        $insight = $this->ask($ai, $page, $locale, $json);
        if (!$insight) {
            if ($ai->quotaExhausted) {
                return $this->error('AI is busy (all providers rate-limited). Try again later.', 429);
            }

            return $this->error('AI could not analyse this page right now.', 503);
        }

        Cache::put($key, $insight, self::CACHE_TTL);

        return $this->success($insight + ['cached' => false]);
    }

    private function ask(AiTextService $ai, string $page, string $locale, string $json): ?array
    {
        $context = self::PAGES[$page] ?? $page;
        $lang = $locale === 'ar'
            ? 'Write every string in Arabic.'
            : 'Write every string in English.';

        $prompt = <<<PROMPT
        You are a senior marketing analyst advising a NON-TECHNICAL business owner.
        They are looking at their "{$context}" page and cannot interpret charts.

        Read the JSON metrics below and reply with ONE decision they should make.

        Rules:
        - Be concrete and specific. Name the exact campaign / page / channel / variant from the data.
        - Quote real numbers from the data to justify the decision. Never invent numbers.
        - Explain "why" in everyday words. No jargon (no "CTR", "p-value", "attribution").
        - If the data is too thin or flat to justify a decision, say so honestly and set severity "info".
        - Actions must be things they can do this week.
        - {$lang}

        Reply with ONLY valid JSON, no markdown fence, exactly this shape:
        {
          "verdict": "one short sentence: the decision to make",
          "severity": "good" | "warning" | "critical" | "info",
          "why": ["2-3 short sentences, each citing a real number from the data"],
          "actions": ["1-3 concrete steps to take this week"],
          "confidence": "high" | "medium" | "low"
        }

        METRICS:
        {$json}
        PROMPT;

        $result = $ai->generate($prompt);
        if (!$result) {
            return null;
        }
        $text = $result['text'];

        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $parsed = json_decode(trim($text), true);

        if (!is_array($parsed) || empty($parsed['verdict'])) {
            return null;
        }

        return [
            'verdict' => (string) $parsed['verdict'],
            'severity' => in_array($parsed['severity'] ?? '', ['good', 'warning', 'critical', 'info'], true)
                ? $parsed['severity'] : 'info',
            'why' => array_values(array_filter(array_map('strval', (array) ($parsed['why'] ?? [])))),
            'actions' => array_values(array_filter(array_map('strval', (array) ($parsed['actions'] ?? [])))),
            'confidence' => in_array($parsed['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? $parsed['confidence'] : 'medium',
            'provider' => $result['provider'],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
