<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AiReport;
use App\Models\Domain;
use App\Services\ClickHouseService;
use App\Services\AnalyticsQueryService;
use App\Services\InsightEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/analytics/{domainId}/summary
 *
 * A single endpoint that aggregates all major KPIs in one call for the
 * "Full Summary" dashboard page. Includes:
 *  - Traffic overview (visitors, sessions, bounce, avg duration)
 *  - Top pages, top countries, top referrers
 *  - Device / browser breakdown
 *  - Campaign top performers
 *  - Engaged visitors count
 *  - UX score snapshot
 *  - Recent custom events
 *  - Period-over-period deltas
 */
class SummaryController extends Controller
{
    public function __construct(
        private ClickHouseService $ch,
        private AnalyticsQueryService $analytics,
        private InsightEngine $insights,
    ) {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $did = (int) $domain->id;

        // Custom date range: if both ?from=YYYY-MM-DD&to=YYYY-MM-DD are provided, use them directly.
        $fromParam = $request->query('from');
        $toParam = $request->query('to');
        if (
            $fromParam && $toParam
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromParam)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toParam)
        ) {
            $start = $fromParam;
            $end = $toParam;
        } else {
            $period = $request->query('period', '30d');
            [$start, $end] = $this->parsePeriodDates($period);
        }
        [$prevStart, $prevEnd] = $this->prevPeriod($start, $end);

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $prevStartDt = $prevStart . ' 00:00:00';
        $prevEndDt = $prevEnd . ' 23:59:59';

        // ── Core traffic metrics (derived from events — no stale sessions columns) ──
        // Subquery groups by session_id to compute per-session stats, then the outer
        // query aggregates across all sessions for the period.
        $sessionSubquery = fn(string $start, string $end) =>
            "SELECT any(visitor_id) AS visitor_id, session_id,
                    countIf(type = 'pageview')                      AS pv_count,
                    max(toUnixTimestamp(ts)) - min(toUnixTimestamp(ts)) AS dur
             FROM events
             WHERE domain_id = {$did} AND ts BETWEEN '{$start}' AND '{$end}'
             GROUP BY session_id";

        $traffic = $this->ch->select("
            SELECT
                uniq(visitor_id)                                    AS visitors,
                count()                                             AS sessions,
                sum(pv_count)                                       AS pageviews,
                round(avgIf(dur, dur > 0))                          AS avg_duration,
                round(countIf(pv_count = 1) / count() * 100, 1)    AS bounce_rate,
                round(avg(pv_count), 1)                             AS avg_pages
            FROM ({$sessionSubquery($startDt, $endDt)})
        ");
        $t = $traffic[0] ?? [];

        $prevTraffic = $this->ch->select("
            SELECT
                uniq(visitor_id)                                    AS visitors,
                count()                                             AS sessions,
                sum(pv_count)                                       AS pageviews,
                round(avgIf(dur, dur > 0))                          AS avg_duration,
                round(countIf(pv_count = 1) / count() * 100, 1)    AS bounce_rate,
                round(avg(pv_count), 1)                             AS avg_pages
            FROM ({$sessionSubquery($prevStartDt, $prevEndDt)})
        ");
        $pt = $prevTraffic[0] ?? [];

        // ── Top pages ─────────────────────────────────────────────────────────
        $topPages = $this->ch->select("
            SELECT url, count() AS views
            FROM events
            WHERE domain_id={$did} AND type='pageview'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY url ORDER BY views DESC LIMIT 5
        ");

        // ── Top countries ─────────────────────────────────────────────────────
        $topCountries = $this->ch->select("
            SELECT if(country = '', 'Unknown', country) AS country,
                   uniq(session_id) AS sessions
            FROM events
            WHERE domain_id = {$did} AND type = 'pageview'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY country ORDER BY sessions DESC LIMIT 5
        ");

        // ── Top referrers ─────────────────────────────────────────────────────
        $topReferrers = $this->ch->select("
            SELECT if(referrer='','(direct)',referrer) AS referrer, count() AS sessions
            FROM events
            WHERE domain_id={$did} AND type='pageview'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY referrer ORDER BY sessions DESC LIMIT 5
        ");

        // ── Device split ─────────────────────────────────────────────────────
        $devices = $this->ch->select("
            SELECT device_type, count() AS sessions
            FROM events
            WHERE domain_id={$did} AND type='pageview'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY device_type ORDER BY sessions DESC
        ");

        // ── Top campaign ─────────────────────────────────────────────────────
        $topCampaign = $this->ch->select("
            SELECT
                if(us = '', '(direct)', us)   AS source,
                if(uc = '', '(none)', uc)      AS campaign,
                count()                        AS sessions,
                uniq(visitor_id)               AS visitors,
                round(avgIf(dur, dur > 0))     AS avg_duration
            FROM (
                SELECT
                    session_id,
                    any(visitor_id)                                     AS visitor_id,
                    anyIf(utm_source,   utm_source != '')               AS us,
                    anyIf(utm_campaign, utm_campaign != '')             AS uc,
                    max(toUnixTimestamp(ts)) - min(toUnixTimestamp(ts)) AS dur
                FROM events
                WHERE domain_id = {$did}
                  AND ts BETWEEN '{$startDt}' AND '{$endDt}'
                GROUP BY session_id
            )
            GROUP BY source, campaign
            ORDER BY sessions DESC LIMIT 5
        ");

        // ── Engaged visitors count ────────────────────────────────────────────
        $engaged = $this->ch->select("
            SELECT count() AS total
            FROM (
                SELECT
                    visitor_id,
                    uniq(session_id)                    AS scount,
                    round(avgIf(dur, dur > 0))          AS avg_dur,
                    round(avg(pv_count), 1)             AS avg_pgs
                FROM (
                    SELECT
                        visitor_id, session_id,
                        max(toUnixTimestamp(ts)) - min(toUnixTimestamp(ts)) AS dur,
                        countIf(type = 'pageview')  AS pv_count
                    FROM events
                    WHERE domain_id = {$did}
                      AND ts BETWEEN '{$startDt}' AND '{$endDt}'
                    GROUP BY visitor_id, session_id
                )
                GROUP BY visitor_id
                HAVING scount >= 2 OR avg_dur >= 120 OR avg_pgs >= 3
            )
        ");

        // ── UX score (from PostgreSQL ux_scores) ──────────────────────────────
        $uxScore = \DB::table('ux_scores')
            ->where('domain_id', $did)
            ->orderByDesc('calculated_at')
            ->select(['score', 'breakdown', 'calculated_at'])
            ->first();

        // ── Recent custom events ──────────────────────────────────────────────
        $customEvents = $this->ch->select("
            SELECT name, count() AS occurrences
            FROM custom_events
            WHERE domain_id={$did}
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY name ORDER BY occurrences DESC LIMIT 5
        ");

        // ── Daily trend (chart) ───────────────────────────────────────────────
        $trend = $this->ch->select("
            SELECT toDate(ts)         AS date,
                   uniq(visitor_id)   AS visitors,
                   uniq(session_id)   AS sessions
            FROM events
            WHERE domain_id = {$did} AND type = 'pageview'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY date ORDER BY date ASC
        ");

        // ── Top issues — deterministic stats engine, ranked by impact ─────────
        // Same detectors that power every page's own "Insights" panel — this is
        // the single "what needs my attention" view across all of them, cached
        // 15 min inside InsightEngine's caller (InsightController); here we call
        // it directly since this endpoint aggregates rather than showing one page.
        $topIssues = array_slice($this->insights->overview($did), 0, 5);

        // ── Latest AI report — headline + top suggestions, not the full report ──
        $aiReport = AiReport::where('domain_id', $did)->latest('generated_at')->first();
        $aiSummary = null;
        if ($aiReport) {
            $content = $aiReport->content ?? [];
            $aiSummary = [
                'summary' => $content['summary'] ?? null,
                'top_insight' => $content['top_insight'] ?? null,
                'top_suggestions' => collect($content['suggestions'] ?? [])
                    ->filter(fn ($s) => ($s['priority'] ?? null) === 'high')
                    ->take(3)->values(),
                'generated_at' => $aiReport->generated_at,
            ];
        }

        // ── UX issues snapshot (rage/dead clicks, JS errors) — ux_events uses
        // `created_at`, not `ts`, unlike every other ClickHouse table here. ────
        $uxIssues = $this->ch->select("
            SELECT type, count() AS occurrences, uniq(visitor_id) AS affected
            FROM ux_events
            WHERE domain_id = {$did} AND type IN ('rage_click', 'dead_click', 'js_error')
              AND created_at BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY type ORDER BY occurrences DESC
        ");

        // ── Web Vitals rating snapshot — same thresholds as UxWebVitalsController ──
        $vitalsRow = $this->ch->select("
            SELECT
                countIf(JSONExtractString(details, 'rating') = 'good')             AS good,
                countIf(JSONExtractString(details, 'rating') = 'needs-improvement') AS needs_improvement,
                countIf(JSONExtractString(details, 'rating') = 'poor')             AS poor
            FROM ux_events
            WHERE domain_id = {$did} AND type = 'web_vitals'
              AND created_at BETWEEN '{$startDt}' AND '{$endDt}'
        ");
        $vv = $vitalsRow[0] ?? ['good' => 0, 'needs_improvement' => 0, 'poor' => 0];
        $vTotal = max(1, (int) $vv['good'] + (int) $vv['needs_improvement'] + (int) $vv['poor']);
        $vitalsRating = ((int) $vv['poor']) / $vTotal >= 0.2
            ? 'poor'
            : ((((int) $vv['needs_improvement'] + (int) $vv['poor']) / $vTotal >= 0.25) ? 'needs-improvement' : 'good');

        // ── Revenue snapshot (simple total — full multi-touch attribution lives
        // on the Campaigns page; this is just "how much came in") ─────────────
        $revenueRow = $this->ch->select("
            SELECT sum(value) AS revenue, count() AS orders
            FROM conversions WHERE domain_id = {$did} AND ts BETWEEN '{$startDt}' AND '{$endDt}'
        ");
        $prevRevenueRow = $this->ch->select("
            SELECT sum(value) AS revenue, count() AS orders
            FROM conversions WHERE domain_id = {$did} AND ts BETWEEN '{$prevStartDt}' AND '{$prevEndDt}'
        ");

        // ── Recent alerts fired (last 7 days, regardless of summary period) ────
        $recentAlerts = DB::table('notifications')
            ->where('domain_id', $did)
            ->where('type', 'alert')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['title', 'body', 'created_at']);

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => [
                'period' => ['start' => $start, 'end' => $end],
                'traffic' => $t,
                'prev_traffic' => $pt,
                'top_pages' => $topPages,
                'top_countries' => $topCountries,
                'top_referrers' => $topReferrers,
                'devices' => $devices,
                'top_campaigns' => $topCampaign,
                'engaged_count' => (int) ($engaged[0]['total'] ?? 0),
                'ux_score' => $uxScore ? [
                    'score' => $uxScore->score,
                    'breakdown' => json_decode($uxScore->breakdown ?? '{}', true),
                    'calculated_at' => $uxScore->calculated_at,
                ] : null,
                'custom_events' => $customEvents,
                'trend' => $trend,
                'top_issues' => $topIssues,
                'ai_summary' => $aiSummary,
                'ux_issues' => $uxIssues,
                'web_vitals' => [
                    'rating' => $vitalsRating,
                    'good' => (int) $vv['good'],
                    'needs_improvement' => (int) $vv['needs_improvement'],
                    'poor' => (int) $vv['poor'],
                ],
                'revenue' => [
                    'total' => round((float) ($revenueRow[0]['revenue'] ?? 0), 2),
                    'orders' => (int) ($revenueRow[0]['orders'] ?? 0),
                    'prev_total' => round((float) ($prevRevenueRow[0]['revenue'] ?? 0), 2),
                ],
                'recent_alerts' => $recentAlerts,
            ],
        ]);
    }

    private function parsePeriodDates(string $period): array
    {
        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            '1y' => 365,
            default => 30,
        };
        return [
            now()->subDays($days)->toDateString(),
            now()->toDateString(),
        ];
    }

    private function prevPeriod(string $start, string $end): array
    {
        $s = \Carbon\Carbon::parse($start);
        $e = \Carbon\Carbon::parse($end);
        $diff = $s->diffInDays($e) + 1;
        return [
            $s->subDays($diff)->toDateString(),
            $e->subDays($diff)->toDateString(),
        ];
    }
}
