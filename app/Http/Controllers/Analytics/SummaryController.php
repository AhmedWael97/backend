<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    ) {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $period = $request->query('period', '30d');
        $did = (int) $domain->id;

        [$start, $end] = $this->parsePeriodDates($period);
        [$prevStart, $prevEnd] = $this->prevPeriod($start, $end);

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $prevStartDt = $prevStart . ' 00:00:00';
        $prevEndDt = $prevEnd . ' 23:59:59';

        // ── Core traffic metrics ──────────────────────────────────────────────
        $traffic = $this->ch->select("
            SELECT
                uniq(visitor_id)                                   AS visitors,
                count()                                            AS sessions,
                sum(page_count)                                    AS pageviews,
                round(avg(duration_seconds))                       AS avg_duration,
                round(countIf(page_count=1)/count()*100,1)         AS bounce_rate,
                round(avg(page_count),1)                           AS avg_pages
            FROM sessions
            WHERE domain_id={$did}
              AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
        ");
        $t = $traffic[0] ?? [];

        $prevTraffic = $this->ch->select("
            SELECT
                uniq(visitor_id) AS visitors,
                count()          AS sessions,
                sum(page_count)  AS pageviews,
                round(avg(duration_seconds)) AS avg_duration,
                round(countIf(page_count=1)/count()*100,1) AS bounce_rate
            FROM sessions
            WHERE domain_id={$did}
              AND started_at BETWEEN '{$prevStartDt}' AND '{$prevEndDt}'
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
            SELECT if(country='','Unknown',country) AS country, count() AS sessions
            FROM sessions
            WHERE domain_id={$did}
              AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
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
                if(utm_source='','(direct)',utm_source) AS source,
                if(utm_campaign='','(none)',utm_campaign) AS campaign,
                count() AS sessions,
                uniq(visitor_id) AS visitors,
                round(avg(duration_seconds)) AS avg_duration
            FROM sessions
            WHERE domain_id={$did}
              AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY source, campaign
            ORDER BY sessions DESC LIMIT 5
        ");

        // ── Engaged visitors count ────────────────────────────────────────────
        $engaged = $this->ch->select("
            SELECT count() AS total
            FROM (
                SELECT
                    visitor_id,
                    count()                          AS scount,
                    round(avg(duration_seconds))     AS avg_dur,
                    round(avg(page_count),1)         AS avg_pgs
                FROM sessions
                WHERE domain_id={$did}
                  AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
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
            SELECT toDate(started_at) AS date,
                   uniq(visitor_id) AS visitors,
                   count()          AS sessions
            FROM sessions
            WHERE domain_id={$did}
              AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY date ORDER BY date ASC
        ");

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
