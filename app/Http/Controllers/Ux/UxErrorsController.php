<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxErrorsController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        $rows = $this->clickhouse->select("
                        SELECT
                                JSONExtractString(details, 'msg') AS message,
                                JSONExtractString(details, 'src') AS file,
                                JSONExtractInt(details, 'ln')     AS line,
                                any(JSONExtractString(details, 'stk')) AS stack,
                                count()                               AS occurrences,
                                uniq(visitor_id)                      AS affected_visitors,
                                max(created_at)                       AS last_seen
                        FROM ux_events
                        WHERE domain_id = {$domain->id}
                            AND type = 'js_error'
                            AND created_at >= '{$from} 00:00:00'
                            AND created_at <= '{$to} 23:59:59'
                        GROUP BY message, file, line
                        ORDER BY occurrences DESC
                        LIMIT 200
        ");

        return $this->success($rows);
    }
}
