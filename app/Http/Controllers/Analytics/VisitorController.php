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
     * One row per session (entry/exit page, page count, duration already live
     * on the `sessions` table) — was capped at 10, silently hiding sessions
     * for anyone with more than that. Journey (what they actually did inside
     * one session) is a separate lazy-loaded call — see journey() below —
     * so this stays cheap regardless of how many sessions a visitor has.
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
             LIMIT 200",
            ['visitor_id' => $visitorId]
        );

        return $this->success([
            'visitor_id' => $visitorId,
            'sessions' => $sessions,
            'session_count' => count($sessions),
            'identified_as' => null,
        ]);
    }

    /**
     * GET /api/analytics/{domainId}/visitors/{visitorId}/sessions/{sessionId}/journey
     * Every event in one session, in order — pageviews, clicks, scroll depth,
     * rage/dead clicks, JS errors, custom events, everything the tracker
     * recorded. `props` is returned as its raw JSON string; the frontend picks
     * out the fields relevant to each event type (click target, scroll %, etc).
     */
    public function journey(Request $request, int $domainId, string $visitorId, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $did = (int) $domain->id;

        $events = $this->ch->select(
            "SELECT type, url, title, props, toUnixTimestamp(ts) AS ts
             FROM events
             WHERE domain_id = {$did}
               AND visitor_id = :visitor_id
               AND session_id = :session_id
             ORDER BY ts ASC
             LIMIT 300",
            ['visitor_id' => $visitorId, 'session_id' => $sessionId]
        );

        $customEvents = $this->ch->select(
            "SELECT name, props, toUnixTimestamp(ts) AS ts
             FROM custom_events
             WHERE domain_id = {$did}
               AND visitor_id = :visitor_id
               AND session_id = :session_id
             ORDER BY ts ASC
             LIMIT 100",
            ['visitor_id' => $visitorId, 'session_id' => $sessionId]
        );

        return $this->success([
            'session_id' => $sessionId,
            'events' => $events,
            'custom_events' => $customEvents,
        ]);
    }
}
