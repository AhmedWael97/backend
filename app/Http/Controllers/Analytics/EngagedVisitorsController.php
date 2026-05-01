<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/analytics/{domainId}/engaged-visitors
 *
 * Ranks visitors by an engagement score computed from session + event signals:
 *   score = min(100,
 *       clamp(avg_duration/120, 0, 30)          -- up to 30 pts for 2+ min avg
 *     + clamp(avg_pages * 10, 0, 30)            -- up to 30 pts for 3+ pages
 *     + clamp(total_clicks * 2, 0, 20)          -- up to 20 pts for 10+ clicks
 *     + clamp(total_sessions * 5, 0, 15)        -- up to 15 pts for 3+ returns
 *     + clamp(scroll_depth_avg, 0, 5)           -- up to 5 pts for deep scroll
 *   )
 *
 * Returns paginated list sorted by score DESC.
 */
class EngagedVisitorsController extends Controller
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
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $domainId = (int) $domain->id;

        // ── Base session aggregation per visitor ──────────────────────────────
        $sessionMetrics = $this->ch->select("
            SELECT
                visitor_id,
                count()                                 AS total_sessions,
                round(avg(duration_seconds))            AS avg_duration,
                round(avg(page_count), 1)               AS avg_pages,
                max(started_at)                         AS last_seen,
                min(started_at)                         AS first_seen,
                any(country)                            AS country,
                any(device)                             AS device_type,
                any(browser)                            AS browser,
                any(company_name)                       AS company
            FROM sessions
            WHERE domain_id = {$domainId}
              AND started_at BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY visitor_id
        ");

        if (empty($sessionMetrics)) {
            return response()->json([
                'statusCode' => 200,
                'statusText' => 'success',
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $limit, 'current_page' => $page],
            ]);
        }

        // ── Click counts per visitor ──────────────────────────────────────────
        $clickRows = $this->ch->select("
            SELECT visitor_id, count() AS total_clicks
            FROM events
            WHERE domain_id = {$domainId}
              AND type = 'click'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY visitor_id
        ");
        $clickMap = [];
        foreach ($clickRows as $r) {
            $clickMap[$r['visitor_id']] = (int) $r['total_clicks'];
        }

        // ── Avg scroll depth per visitor (from scroll_depth events) ──────────
        $scrollRows = $this->ch->select("
            SELECT visitor_id, max(CAST(JSONExtractString(props, 'depth') AS Int32)) AS max_scroll
            FROM events
            WHERE domain_id = {$domainId}
              AND type = 'scroll_depth'
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY visitor_id
        ");
        $scrollMap = [];
        foreach ($scrollRows as $r) {
            $scrollMap[$r['visitor_id']] = (int) ($r['max_scroll'] ?? 0);
        }

        // ── Score each visitor ────────────────────────────────────────────────
        $scored = [];
        foreach ($sessionMetrics as $v) {
            $vid = $v['visitor_id'];
            $dur = min(30, ($v['avg_duration'] / 120) * 30);
            $pages = min(30, $v['avg_pages'] * 10);
            $clicks = min(20, ($clickMap[$vid] ?? 0) * 2);
            $sessions = min(15, $v['total_sessions'] * 5);
            $scroll = min(5, ($scrollMap[$vid] ?? 0) / 20);

            $v['total_clicks'] = $clickMap[$vid] ?? 0;
            $v['max_scroll'] = $scrollMap[$vid] ?? 0;
            $v['score'] = (int) min(100, round($dur + $pages + $clicks + $sessions + $scroll));
            $scored[] = $v;
        }

        // Sort by score DESC then paginate in PHP (dataset is per-domain, manageable)
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $total = count($scored);
        $paged = array_slice($scored, $offset, $limit);

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => $paged,
            'meta' => [
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
            ],
        ]);
    }
}
