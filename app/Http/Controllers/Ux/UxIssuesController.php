<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxIssuesController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $type = $request->query('type');
        $url = $request->query('url');
        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $where = "domain_id = {$domain->id}
            AND created_at >= '{$from} 00:00:00'
            AND created_at <= '{$to} 23:59:59'";

        if ($type) {
            $safeType = addslashes($type);
            $where .= " AND type = '{$safeType}'";
        }
        if ($url) {
            $safeUrl = addslashes($url);
            $where .= " AND url LIKE '%{$safeUrl}%'";
        }

        $rows = $this->clickhouse->select("
            SELECT
                type,
                replaceRegexpOne(url, '^(https?://)www\\.', '\\\\1') AS url,
                element_selector,
                details,
                count() AS occurrences,
                uniq(visitor_id) AS affected_visitors,
                max(created_at)  AS last_seen,
                any(session_id)  AS sample_session_id
            FROM ux_events
            WHERE {$where}
            GROUP BY type, url, element_selector, details
            ORDER BY occurrences DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $total = (int) ($this->clickhouse->select("
            SELECT count() AS c
            FROM ux_events
            WHERE {$where}
        ")[0]['c'] ?? 0);

        return $this->success([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
        ]);
    }
}
