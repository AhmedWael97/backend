<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxScrollDepthController extends Controller
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

        // Returns one row per (page, depth_mark) so the frontend can build
        // a per-page funnel showing how many unique visitors reached each mark.
        $rows = $this->clickhouse->select("
            SELECT
                replaceRegexpOne(url, '^(https?://)www\\.', '\\\\1') AS url,
                JSONExtractInt(details, 'depth') AS depth,
                count()              AS hits,
                uniq(visitor_id)     AS visitors
            FROM ux_events
            WHERE domain_id = {$domain->id}
              AND type = 'scroll_depth'
              AND created_at >= '{$from} 00:00:00'
              AND created_at <= '{$to} 23:59:59'
            GROUP BY url, depth
            ORDER BY url ASC, depth ASC
            LIMIT 2000
        ");

        // Reshape into per-page objects with depth marks as keys
        $pages = [];
        foreach ($rows as $row) {
            $url = $row['url'];
            $depth = (int) $row['depth'];
            if (!isset($pages[$url])) {
                $pages[$url] = [
                    'url' => $url,
                    'd25' => 0,
                    'd50' => 0,
                    'd75' => 0,
                    'd100' => 0,
                    'total' => 0,
                ];
            }
            $pages[$url]["d{$depth}"] = (int) $row['visitors'];
            // total = max visitors (those who reached at least 25%)
            if ($depth === 25) {
                $pages[$url]['total'] = (int) $row['visitors'];
            }
        }

        $result = array_values($pages);

        // Sort by total visitors descending
        usort($result, fn($a, $b) => $b['total'] <=> $a['total']);

        return $this->success($result);
    }
}
