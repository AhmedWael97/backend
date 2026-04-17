<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitorController extends Controller
{
    /**
     * GET /api/analytics/{domainId}/visitors
     */
    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;
        $search = $request->query('search');
        $device = $request->query('device');

        // Build base query from sessions table in PostgreSQL
        $query = DB::table('sessions as s')
            ->where('s.domain_id', $domain->id)
            ->select([
                's.visitor_id',
                DB::raw('MAX(s.started_at) as last_seen'),
                DB::raw('COUNT(*) as session_count'),
                DB::raw('MAX(s.device_type) as device_type'),
                DB::raw('MAX(s.country) as country'),
                DB::raw('MAX(s.browser) as browser'),
            ])
            ->groupBy('s.visitor_id');

        if ($device && $device !== 'all') {
            $query->where('s.device_type', $device);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('s.visitor_id', 'like', "%{$search}%");
            });
        }

        $total = DB::table(DB::raw("({$query->toSql()}) as v"))
            ->mergeBindings($query)
            ->count();
        $visitors = $query->orderByDesc('last_seen')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $visitors,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
        ]);
    }

    /**
     * GET /api/analytics/{domainId}/visitors/{visitorId}
     */
    public function show(Request $request, int $domainId, string $visitorId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Recent sessions
        $sessions = DB::table('sessions')
            ->where('domain_id', $domain->id)
            ->where('visitor_id', $visitorId)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        // Recent page views from events (if events table is accessible via query)
        // Fallback to empty array if ClickHouse is not available in current context
        $pageviews = [];

        return response()->json([
            'data' => [
                'visitor_id' => $visitorId,
                'sessions' => $sessions,
                'pageviews' => $pageviews,
                'identified_as' => null,
            ],
        ]);
    }
}
