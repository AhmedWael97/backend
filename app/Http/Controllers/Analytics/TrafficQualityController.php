<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/analytics/{domainId}/traffic-quality?from=&to=
 *
 * Per-day "traffic quality" score (0-100) — how real/engaged visitors are on
 * a given day, so a spend decision (this audience vs. that one) can be made
 * on more than raw visitor count. Deterministic average of four 0-100
 * sub-scores, each derived directly from data already tracked:
 *   - time:   avg session duration, capped at 120s = 100
 *   - depth:  avg pages per session, 1 page = 0, 5+ pages = 100
 *   - scroll: avg max scroll depth reached (already 0-100)
 *   - engage: 100 - bounce_rate
 * No ML, no external signal — every input is a metric already shown
 * elsewhere in the dashboard, just combined into one number per day.
 */
class TrafficQualityController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)->accessibleBy($user)->firstOrFail();
        $domainId = (int) $domain->id;

        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        $sessionRows = $this->ch->select("
            SELECT
                day,
                count()                    AS sessions,
                uniqExact(visitor_id)      AS visitors,
                sum(pv_count)              AS pageviews,
                avg(session_duration)      AS avg_duration,
                countIf(pv_count = 1)      AS bounced
            FROM (
                SELECT
                    session_id,
                    any(visitor_id) AS visitor_id,
                    toDate(min(ts)) AS day,
                    countIf(type = 'pageview') AS pv_count,
                    maxIf(duration, type = 'time_on_page' AND duration > 0) AS session_duration
                FROM events
                WHERE domain_id = {$domainId}
                  AND ts >= '{$from} 00:00:00' AND ts <= '{$to} 23:59:59'
                GROUP BY session_id
            )
            GROUP BY day
            ORDER BY day ASC
        ");

        $scrollRows = $this->ch->select("
            SELECT day, avg(max_depth) AS avg_scroll
            FROM (
                SELECT
                    toDate(created_at) AS day,
                    session_id,
                    max(JSONExtractInt(details, 'depth')) AS max_depth
                FROM ux_events
                WHERE domain_id = {$domainId}
                  AND type = 'scroll_depth'
                  AND created_at >= '{$from} 00:00:00' AND created_at <= '{$to} 23:59:59'
                GROUP BY day, session_id
            )
            GROUP BY day
        ");

        $scrollByDay = [];
        foreach ($scrollRows as $r) {
            $scrollByDay[$r['day']] = (float) $r['avg_scroll'];
        }

        $days = [];
        foreach ($sessionRows as $r) {
            $day = $r['day'];
            $sessions = (int) $r['sessions'];
            $avgDuration = (float) $r['avg_duration'];
            $avgPages = $sessions > 0 ? (float) $r['pageviews'] / $sessions : 0.0;
            $bounceRate = $sessions > 0 ? (float) $r['bounced'] / $sessions * 100 : 0.0;
            $avgScroll = $scrollByDay[$day] ?? 0.0;

            $timeScore = min(100, $avgDuration / 120 * 100);
            $depthScore = min(100, max(0, ($avgPages - 1) / 4 * 100));
            $scrollScore = min(100, max(0, $avgScroll));
            $engageScore = max(0, 100 - $bounceRate);

            $score = ($timeScore + $depthScore + $scrollScore + $engageScore) / 4;

            $days[] = [
                'date' => $day,
                'score' => (int) round($score),
                'visitors' => (int) $r['visitors'],
                'sessions' => $sessions,
                'avg_duration' => (int) round($avgDuration),
                'avg_pages' => round($avgPages, 1),
                'avg_scroll' => (int) round($avgScroll),
                'bounce_rate' => round($bounceRate, 1),
            ];
        }

        return $this->success(['from' => $from, 'to' => $to, 'days' => $days]);
    }
}
