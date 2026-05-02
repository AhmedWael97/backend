<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxHeatmapController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $url = $request->query('url', '');
        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        $safeUrl = addslashes($url);
        $urlFilter = $safeUrl !== ''
            ? "AND url LIKE '%{$safeUrl}%'"
            : '';

        $rows = $this->clickhouse->select("
            SELECT
                type,
                JSONExtractFloat(details, 'x') AS x,
                JSONExtractFloat(details, 'y') AS y,
                count() AS count
            FROM ux_events
            WHERE domain_id = {$domain->id}
                            AND type IN ('click', 'rage_click', 'dead_click')
                            {$urlFilter}
              AND created_at >= '{$from} 00:00:00'
              AND created_at <= '{$to} 23:59:59'
                            AND x > 0
                            AND y > 0
            GROUP BY type, x, y
            ORDER BY count DESC
            LIMIT 2000
        ");

        return $this->success($rows);
    }
}
