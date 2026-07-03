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

        // ── 9. Campaign / UTM attribution — surfaces paid-traffic quality ─────
        $utm = $clickhouse->select("
            SELECT
                if(utm_source = '', '(none)', utm_source)     AS source,
                if(utm_medium = '', '(none)', utm_medium)     AS medium,
                if(utm_campaign = '', '(none)', utm_campaign) AS campaign,
                uniq(session_id) AS sessions,
                uniq(visitor_id) AS visitors
            FROM events
            WHERE domain_id = {$domainId} AND type = 'pageview'
              AND ts >= '{$from30}' AND ts < '{$to}'
            GROUP BY source, medium, campaign
            ORDER BY sessions DESC LIMIT 15
        ");

        // ── 10. Entry (landing) pages — where sessions begin ─────────────────
        $entryPages = $clickhouse->select("
            SELECT url, uniq(session_id) AS entries
            FROM (
                SELECT session_id, argMin(url, ts) AS url
                FROM events
                WHERE domain_id = {$domainId} AND type = 'pageview'
                  AND ts >= '{$from30}' AND ts < '{$to}'
                GROUP BY session_id
            )
            GROUP BY url ORDER BY entries DESC LIMIT 10
        ");

        // ── 11. Conversion signals: sign-ups (identify) + purchase events ────
        $signupRows = $clickhouse->select("
            SELECT count() AS identifies, uniq(visitor_id) AS identified_visitors
            FROM events
            WHERE domain_id = {$domainId} AND type = 'identify'
              AND ts >= '{$from30}' AND ts < '{$to}'
        ");
        $purchaseRows = $clickhouse->select("
            SELECT count() AS purchase_events, uniq(visitor_id) AS buyers
            FROM custom_events
            WHERE domain_id = {$domainId}
              AND name IN ('purchase','order_completed','checkout_complete')
              AND ts >= '{$from30}' AND ts < '{$to}'
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

        // Conversion signals
        $identifies = (int) ($signupRows[0]['identifies'] ?? 0);
        $identifiedVisitors = (int) ($signupRows[0]['identified_visitors'] ?? 0);
        $purchaseEvents = (int) ($purchaseRows[0]['purchase_events'] ?? 0);
        $buyers = (int) ($purchaseRows[0]['buyers'] ?? 0);

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
            'campaigns_utm' => $utm,
            'entry_pages' => $entryPages,
            'conversions' => [
                'signups_identifies' => $identifies,
                'identified_visitors' => $identifiedVisitors,
                'signup_rate_pct' => $uv30 > 0 ? round($identifiedVisitors / $uv30 * 100, 2) : 0,
                'purchase_events' => $purchaseEvents,
                'buyers' => $buyers,
                'purchase_conversion_rate_pct' => $uv30 > 0 ? round($buyers / $uv30 * 100, 2) : 0,
                'revenue_tracking_configured' => $purchaseEvents > 0,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $systemPrompt = <<<'PROMPT'
You are a senior growth & conversion-rate-optimization consultant analyzing ONE real website's
analytics. Produce a sharp, specific, money-focused report a busy founder can act on today.

HOW TO THINK (do this before writing):
1. TRAFFIC QUALITY over volume. Compare traffic_sources and campaigns_utm by ENGAGEMENT, not just
   count: visitors vs sessions, bounce, time on page. Explicitly call out low-quality or
   UN-ATTRIBUTED paid traffic — e.g. a huge "(none)" UTM bucket, or one referrer dominating — because
   that means ad spend can't be measured and is likely wasted. Name the exact source and its numbers.
2. CONVERSION & MEASUREMENT. Look at conversions.* . If purchase_events = 0 /
   revenue_tracking_configured = false, the site is NOT measuring revenue at all — this is a critical
   gap: make it the #1 suggestion (category "measurement", priority "high") with the exact fix (fire
   EYE.purchase(value, currency, orderId) on the order-confirmation page), because every ROI feature
   depends on it. Also assess signup_rate_pct.
3. TREND. Use wow_* deltas to say whether things are improving or declining, with the numbers.
4. FRICTION. Use ux_friction (rage/dead clicks, errors) and entry_pages to find where visitors bounce
   or struggle. Tie friction to specific pages.
5. GROUND EVERYTHING. Every claim must cite a real number from the data. NEVER invent statistics.
   Prefer advice tied to revenue/conversion over vanity metrics.

Return a single JSON object with EXACTLY this structure (no markdown, valid JSON only):
{
  "summary": "4–6 sentences: current state, clearest win, biggest leak, and trend direction — with real numbers.",
  "top_insight": "The single highest-leverage finding in one specific sentence, including the number.",
  "segments": [
    {
      "name": "2–4 word name",
      "description": "2–3 sentences: who they are and what distinguishes them, grounded in device/country/source/entry-page/engagement data.",
      "size_percent": <number 0–100>,
      "traits": ["trait 1", "trait 2", "trait 3"],
      "color": "<hex e.g. #6366f1>"
    }
  ],
  "suggestions": [
    {
      "text": "Specific action: WHAT to change, WHERE (name the page/source/segment), the EXPECTED RESULT, and — in parentheses — which data point justifies it and the rough effort (low/medium/high).",
      "category": "acquisition|conversion|ux|retention|measurement",
      "priority": "high|medium|low",
      "estimated_impact": "<grounded estimate, e.g. 'recover ~X bounced sessions/mo' — or 'enables revenue tracking' when not quantifiable>"
    }
  ],
  "growth_opportunities": [ { "title": "Short title", "detail": "1–2 sentences: the opportunity and how to pursue it, referencing the data." } ],
  "risk_areas": [ { "title": "Short title", "detail": "1–2 sentences: a REAL risk from the data and how to mitigate it." } ]
}

Rules:
- 3–5 segments, grounded in the actual data (not invented personas).
- 5–8 suggestions, ORDERED so #1 has the highest impact-per-effort. If revenue tracking is not
  configured, #1 MUST be to set it up.
- 2–4 growth_opportunities; 1–3 risk_areas (real risks only).
- Every number must come from the provided data. Return ONLY valid JSON.
PROMPT;

        $userMessage = "Website analytics data:\n\n{$dataCtx}";

        // Token / free-run was claimed atomically in AiController::analyze so the
        // user couldn't double-spend across concurrent requests. The job's only
        // responsibility now is to remember the claim so failed() can refund.
        $this->tokenDeducted = !$this->isFreeRun;

        $result = $openai->complete($systemPrompt, $userMessage, 8000);

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
