<?php

namespace App\Jobs;

use App\Models\AiReport;
use App\Models\AiSuggestion;
use App\Models\AudienceSegment;
use App\Models\Domain;
use App\Models\User;
use App\Services\OpenAiService;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnalyzeDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    /**
     * Whether this run consumed a paid token (so we can refund on failure).
     * Set in handle() before the OpenAI call.
     */
    private bool $tokenDeducted = false;

    public function __construct(
        public readonly int $domainId,
        public readonly int $userId,
        public readonly bool $isFreeRun = false,
    ) {
    }

    public function handle(ClickHouseService $clickhouse, OpenAiService $openai): void
    {
        $domain = Domain::findOrFail($this->domainId);
        $user = User::findOrFail($this->userId);

        $domainId = $this->domainId;
        $now = now();
        $from30 = $now->copy()->subDays(30)->format('Y-m-d H:i:s');
        $from7 = $now->copy()->subDays(7)->format('Y-m-d H:i:s');
        $from14 = $now->copy()->subDays(14)->format('Y-m-d H:i:s');
        $to = $now->format('Y-m-d H:i:s');

        // ── 1. Core summary ──────────────────────────────────────────────────
        $summary30 = $clickhouse->select("
            SELECT
                countIf(type = 'pageview')       AS pageviews,
                uniq(visitor_id)                 AS unique_visitors,
                uniq(session_id)                 AS sessions,
                avgIf(duration, duration > 0)    AS avg_duration,
                countIf(pv_count = 1) / greatest(count(), 1) * 100 AS bounce_rate
            FROM (
                SELECT visitor_id, session_id, type, duration,
                       countIf(type='pageview') OVER (PARTITION BY session_id) AS pv_count
                FROM events
                WHERE domain_id = {$domainId} AND ts >= '{$from30}' AND ts < '{$to}'
            )
        ");

        $summary7 = $clickhouse->select("
            SELECT
                countIf(type = 'pageview')       AS pageviews,
                uniq(visitor_id)                 AS unique_visitors,
                uniq(session_id)                 AS sessions
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from7}' AND ts < '{$to}'
        ");

        $summary7prev = $clickhouse->select("
            SELECT
                countIf(type = 'pageview')   AS pageviews,
                uniq(visitor_id)             AS unique_visitors
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from14}' AND ts < '{$from7}'
        ");

        // ── 2. Top pages with engagement ────────────────────────────────────
        $topPages = $clickhouse->select("
            SELECT
                url,
                count()                          AS pageviews,
                uniq(visitor_id)                 AS unique_visitors,
                avgIf(duration, duration > 0)    AS avg_time_on_page
            FROM events
            WHERE domain_id = {$domainId} AND type = 'pageview'
              AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY url ORDER BY pageviews DESC LIMIT 15
        ");

        // ── 3. Traffic sources ───────────────────────────────────────────────
        $referrers = $clickhouse->select("
            SELECT
                if(referrer = '', 'Direct / None', referrer) AS source,
                count() AS visits,
                uniq(visitor_id) AS unique_visitors
            FROM events
            WHERE domain_id = {$domainId} AND type = 'pageview'
              AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY source ORDER BY visits DESC LIMIT 15
        ");

        // ── 4. Geographic top-10 ─────────────────────────────────────────────
        $countries = $clickhouse->select("
            SELECT
                if(country = '', 'Unknown', country) AS country,
                count() AS pageviews,
                uniq(visitor_id) AS unique_visitors
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY country ORDER BY pageviews DESC LIMIT 10
        ");

        // ── 5. Device / browser / OS ─────────────────────────────────────────
        $devices = $clickhouse->select("
            SELECT device_type, count() AS visits
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY device_type ORDER BY visits DESC
        ");

        $browsers = $clickhouse->select("
            SELECT browser, count() AS visits
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY browser ORDER BY visits DESC LIMIT 8
        ");

        // ── 6. Hourly traffic distribution ──────────────────────────────────
        $hourly = $clickhouse->select("
            SELECT
                toHour(ts) AS hour,
                count()    AS events
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from30}' AND ts < '{$to}'
              AND type = 'pageview'
            GROUP BY hour ORDER BY hour ASC
        ");

        // ── 7. UX friction signals ───────────────────────────────────────────
        $uxEvents = $clickhouse->select("
            SELECT type, count() AS count
            FROM ux_events
            WHERE domain_id = {$domainId}
              AND created_at >= '{$from30}' AND created_at < '{$to}'
            GROUP BY type ORDER BY count DESC
        ");

        // ── 8. Custom events (conversion signals) ────────────────────────────
        $customEvents = $clickhouse->select("
            SELECT name, count() AS occurrences, uniq(visitor_id) AS unique_visitors
            FROM custom_events
            WHERE domain_id = {$domainId}
              AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY name ORDER BY occurrences DESC LIMIT 10
        ");

        // Build WoW deltas
        $pv30 = (int) ($summary30[0]['pageviews'] ?? 0);
        $uv30 = (int) ($summary30[0]['unique_visitors'] ?? 0);
        $pv7 = (int) ($summary7[0]['pageviews'] ?? 0);
        $uv7 = (int) ($summary7[0]['unique_visitors'] ?? 0);
        $pv7p = (int) ($summary7prev[0]['pageviews'] ?? 0);
        $uv7p = (int) ($summary7prev[0]['unique_visitors'] ?? 0);

        $pvDelta = $pv7p > 0 ? round(($pv7 - $pv7p) / $pv7p * 100, 1) : null;
        $uvDelta = $uv7p > 0 ? round(($uv7 - $uv7p) / $uv7p * 100, 1) : null;

        $dataCtx = json_encode([
            'domain' => $domain->domain,
            'report_period' => 'Last 30 days',
            'overview' => [
                'pageviews_30d' => $pv30,
                'unique_visitors_30d' => $uv30,
                'sessions_30d' => (int) ($summary30[0]['sessions'] ?? 0),
                'avg_session_duration_sec' => (int) round((float) ($summary30[0]['avg_duration'] ?? 0)),
                'bounce_rate_pct' => round((float) ($summary30[0]['bounce_rate'] ?? 0), 1),
                'wow_pageviews_change_pct' => $pvDelta,
                'wow_visitors_change_pct' => $uvDelta,
            ],
            'top_pages' => $topPages,
            'traffic_sources' => $referrers,
            'countries' => $countries,
            'devices' => $devices,
            'browsers' => $browsers,
            'hourly_distribution' => $hourly,
            'ux_friction' => $uxEvents,
            'custom_events' => $customEvents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $systemPrompt = <<<'PROMPT'
You are an expert digital marketing analyst and conversion rate optimization specialist.
Analyze the provided website analytics data and produce a comprehensive, highly actionable AI report.

Return a single JSON object with EXACTLY this structure:
{
  "summary": "3–4 sentence executive overview of the website's current performance, key wins, and biggest gaps.",
  "top_insight": "The single most important finding or opportunity in one sentence.",
  "segments": [
    {
      "name": "Segment name (descriptive, 2–4 words)",
      "description": "2–3 sentence description of who these visitors are and what distinguishes them.",
      "size_percent": <number 0–100>,
      "traits": ["trait 1", "trait 2", "trait 3"],
      "color": "<hex color e.g. #6366f1>"
    }
  ],
  "suggestions": [
    {
      "text": "Specific, numbered, actionable recommendation with a clear 'what to do' and 'expected result'.",
      "category": "audience|marketing|ux|conversion",
      "priority": "high|medium|low",
      "estimated_impact": "<e.g. +12% conversion rate | -8% bounce rate>"
    }
  ],
  "growth_opportunities": [
    {
      "title": "Short opportunity title",
      "detail": "1–2 sentence explanation of the opportunity and how to pursue it."
    }
  ],
  "risk_areas": [
    {
      "title": "Short risk title",
      "detail": "1–2 sentence explanation of the risk and recommended mitigation."
    }
  ]
}

Rules:
- Provide exactly 3–5 audience segments based on the data.
- Provide 5–8 suggestions, all grounded in the actual numbers.
- Provide 2–4 growth opportunities.
- Provide 1–3 risk areas (only real risks, not generic advice).
- All numbers must come from the data provided — do not invent statistics.
- Return ONLY valid JSON. No markdown, no explanation outside the JSON.
PROMPT;

        $userMessage = "Website analytics data:\n\n{$dataCtx}";

        // Deduct token / mark free run BEFORE the API call so a refund is possible on failure
        DB::transaction(function () use ($user) {
            $user->refresh();
            if ($this->isFreeRun) {
                $user->update(['ai_free_used' => true]);
            } else {
                if ($user->ai_tokens < 1) {
                    throw new \RuntimeException('Insufficient AI tokens.');
                }
                $user->decrement('ai_tokens');
                $this->tokenDeducted = true;
            }
        });

        $result = $openai->complete($systemPrompt, $userMessage, 4096);

        if (empty($result)) {
            $this->refundToken($user);
            Log::warning('AnalyzeDomainJob: empty OpenAI result', ['domain_id' => $this->domainId]);
            return;
        }

        // ── Persist report ───────────────────────────────────────────────────
        AiReport::create([
            'domain_id' => $this->domainId,
            'type' => 'full',
            'content' => $result,
            'generated_at' => $now,
        ]);

        // ── Persist segments ─────────────────────────────────────────────────
        AudienceSegment::where('domain_id', $this->domainId)->delete();
        foreach ($result['segments'] ?? [] as $seg) {
            AudienceSegment::create([
                'domain_id' => $this->domainId,
                'name' => $seg['name'] ?? 'Segment',
                'description' => $seg['description'] ?? '',
                'rules' => ['traits' => $seg['traits'] ?? []],
                'visitor_count' => (int) round(($seg['size_percent'] ?? 0) / 100 * max($uv30, 1)),
                'color' => $seg['color'] ?? '#6366f1',
            ]);
        }

        // ── Persist suggestions ──────────────────────────────────────────────
        AiSuggestion::where('domain_id', $this->domainId)->where('is_dismissed', false)->delete();
        foreach ($result['suggestions'] ?? [] as $sug) {
            AiSuggestion::create([
                'domain_id' => $this->domainId,
                'text' => $sug['text'] ?? '',
                'category' => $sug['category'] ?? 'marketing',
                'priority' => $sug['priority'] ?? 'medium',
                'is_dismissed' => false,
            ]);
        }

        // Chain downstream jobs
        if (class_exists(ComputeUxScoreJob::class)) {
            ComputeUxScoreJob::dispatch($this->domainId)->onQueue('ai');
        }
        if (class_exists(CheckAlertRulesJob::class)) {
            CheckAlertRulesJob::dispatch($this->domainId)->onQueue('ai');
        }
    }

    /**
     * Refund the token if the job fails after deduction.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('AnalyzeDomainJob failed', [
            'domain_id' => $this->domainId,
            'error' => $e->getMessage(),
        ]);

        $user = User::find($this->userId);
        if ($user) {
            $this->refundToken($user);
        }
    }

    private function refundToken(User $user): void
    {
        if ($this->tokenDeducted) {
            $user->increment('ai_tokens');
            $this->tokenDeducted = false;
        } elseif ($this->isFreeRun) {
            $user->update(['ai_free_used' => false]);
        }
    }
}
