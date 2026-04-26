<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxWebVitalsController extends Controller
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
                replaceRegexpOne(url, '^(https?://)www\\.', '\\\\1') AS url,
                round(avg(JSONExtractFloat(details, 'lcp')))  AS avg_lcp,
                round(avg(JSONExtractFloat(details, 'cls')), 3) AS avg_cls,
                round(avg(JSONExtractFloat(details, 'inp')))  AS avg_inp,
                countIf(JSONExtractString(details, 'rating') = 'good')             AS good,
                countIf(JSONExtractString(details, 'rating') = 'needs-improvement') AS needs_improvement,
                countIf(JSONExtractString(details, 'rating') = 'poor')             AS poor,
                count() AS total
            FROM ux_events
            WHERE domain_id = {$domain->id}
              AND type = 'web_vitals'
              AND created_at >= '{$from} 00:00:00'
              AND created_at <= '{$to} 23:59:59'
            GROUP BY url
            ORDER BY poor DESC, total DESC
            LIMIT 200
        ");

        // Compute overall rating per page
        $data = array_map(function (array $row) {
            $good = (int) $row['good'];
            $ni = (int) $row['needs_improvement'];
            $poor = (int) $row['poor'];
            $total = (int) $row['total'];

            $rating = 'good';
            if ($poor / max($total, 1) >= 0.2) {
                $rating = 'poor';
            } elseif (($ni + $poor) / max($total, 1) >= 0.25) {
                $rating = 'needs-improvement';
            }

            return [
                'url' => $row['url'],
                'avg_lcp' => (int) $row['avg_lcp'],
                'avg_cls' => (float) $row['avg_cls'],
                'avg_inp' => (int) $row['avg_inp'],
                'good' => $good,
                'needs_improvement' => $ni,
                'poor' => $poor,
                'total' => $total,
                'rating' => $rating,
            ];
        }, $rows);

        return $this->success($data);
    }
}
