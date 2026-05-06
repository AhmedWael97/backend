<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/analytics/{domainId}/campaigns
 *
 * Returns campaign performance grouped by utm_source / utm_medium / utm_campaign.
 * Metrics per group: sessions, visitors, avg_duration (seconds), avg_pages,
 * bounce_rate (sessions with page_count = 1 / total), conversions (sessions
 * that reached an exit_url matching optional ?goal= param).
 */
class CampaignsController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $start = $request->query('start', now()->subDays(30)->toDateString());
        $end = $request->query('end', now()->toDateString());
        $goal = $request->query('goal');   // optional goal URL substring

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $domainId = (int) $domain->id;

        // ── Campaign table ────────────────────────────────────────────────────
        // Derive bounce_rate, avg_pages, and avg_duration from the events table so
        // they are accurate even before ClickHouse ALTER TABLE mutations are applied.
        $safeGoal = $goal ? str_replace(["\\", "'"], ["\\\\", "\\'"], $goal) : '';
        $goalSubClause = $goal
            ? "maxIf(1, type = 'pageview' AND url LIKE '%{$safeGoal}%') AS has_goal"
            : '0 AS has_goal';

        $rows = $this->ch->select("
            SELECT
                if(utm_source = '', '(direct)', utm_source)                       AS source,
                if(utm_medium = '', '(none)', utm_medium)                         AS medium,
                if(utm_campaign = '', '(none)', utm_campaign)                     AS campaign,
                uniq(session_id)                                                  AS sessions,
                uniq(visitor_id)                                                  AS visitors,
                round(avg(tot_duration))                                          AS avg_duration,
                round(avg(pv_count), 1)                                           AS avg_pages,
                round(toFloat64(countIf(pv_count = 1)) / uniq(session_id) * 100, 1) AS bounce_rate,
                countIf(has_goal = 1)                                             AS conversions,
                max(last_ts)                                                      AS last_seen
            FROM (
                SELECT
                    session_id,
                    visitor_id,
                    anyIf(utm_source,   utm_source   != '') AS utm_source,
                    anyIf(utm_medium,   utm_medium   != '') AS utm_medium,
                    anyIf(utm_campaign, utm_campaign != '') AS utm_campaign,
                    countIf(type = 'pageview')              AS pv_count,
                    sumIf(duration, type = 'time_on_page')  AS tot_duration,
                    max(ts)                                 AS last_ts,
                    {$goalSubClause}
                FROM events
                WHERE domain_id = {$domainId}
                  AND ts BETWEEN '{$startDt}' AND '{$endDt}'
                GROUP BY session_id, visitor_id
            )
            GROUP BY source, medium, campaign
            ORDER BY sessions DESC
            LIMIT 200
        ");

        // ── Top sources (for chart) ───────────────────────────────────────────
        $sources = $this->ch->select("
            SELECT
                if(utm_source = '', '(direct)', utm_source) AS source,
                count()          AS sessions,
                uniq(visitor_id) AS visitors
            FROM sessions
            WHERE domain_id = {$domainId}
              AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY source
            ORDER BY sessions DESC
            LIMIT 10
        ");

        // ── Daily trend per source (top 5 sources) ────────────────────────────
        $topSources = array_slice(array_map(fn($r) => $r['source'], $sources), 0, 5);
        $sourceList = implode(',', array_map(fn($s) => "'" . addslashes($s) . "'", $topSources));

        $trend = [];
        if ($topSources) {
            $trend = $this->ch->select("
                SELECT
                    toDate(started_at) AS date,
                    if(utm_source = '', '(direct)', utm_source) AS source,
                    count()            AS sessions
                FROM sessions
                WHERE domain_id = {$domainId}
                  AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
                  AND if(utm_source = '', '(direct)', utm_source) IN ({$sourceList})
                GROUP BY date, source
                ORDER BY date ASC
            ");
        }

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => [
                'campaigns' => $rows,
                'top_sources' => $sources,
                'trend' => $trend,
            ],
        ]);
    }
}
