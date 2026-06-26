<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    /**
     * GET /api/analytics/{domainId}/visitors
     */
    public function index(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $search = $request->query('search');
        $device = $request->query('device');

        $where = ['domain_id = ' . (int) $domain->id];
        $params = [];

        if ($device && $device !== 'all') {
            $where[] = "device = :device";
            $params['device'] = $device;
        }
        if ($search) {
            $where[] = "visitor_id LIKE :search";
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRows = $this->ch->select(
            "SELECT count() AS total
             FROM (
                 SELECT visitor_id
                 FROM sessions
                 {$whereClause}
                 GROUP BY visitor_id
             )",
            $params
        );
        $total = (int) ($countRows[0]['total'] ?? 0);

        $visitors = $this->ch->select(
            "SELECT
                 visitor_id,
                 max(started_at)  AS last_seen,
                 count()          AS session_count,
                 any(device)   AS device_type,
                 if(any(country) = '', 'Unknown', any(country)) AS country,
                 any(browser)  AS browser
             FROM sessions
             {$whereClause}
             GROUP BY visitor_id
             ORDER BY last_seen DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => $visitors,
            'meta' => [
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
            ],
        ]);
    }

    /**
     * GET /api/analytics/{domainId}/visitors/{visitorId}
     */
    public function show(Request $request, int $domainId, string $visitorId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $domainId = (int) $domain->id;

        $sessions = $this->ch->select(
            "SELECT *
             FROM sessions
             WHERE domain_id = {$domainId}
               AND visitor_id = :visitor_id
             ORDER BY started_at DESC
             LIMIT 10",
            ['visitor_id' => $visitorId]
        );

        $pageviews = $this->ch->select(
            "SELECT url, title, toUnixTimestamp(ts) AS ts
             FROM events
             WHERE domain_id = {$domainId}
               AND visitor_id = :visitor_id
               AND type = 'pageview'
             ORDER BY ts DESC
             LIMIT 50",
            ['visitor_id' => $visitorId]
        );

        return $this->success([
            'visitor_id' => $visitorId,
            'sessions' => $sessions,
            'pageviews' => $pageviews,
            'identified_as' => null,
        ]);
    }
}
