<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ClassifiesTraffic;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/analytics/{domainId}/ltv
 *
 * Lifetime value by acquisition source: each visitor is attributed to their
 * FIRST-touch source, then we sum all their conversion revenue. Shows which
 * channels bring the most valuable customers over their whole lifetime — not
 * just first-purchase. Pure own-data (conversions + events); no integration.
 */
class LtvController extends Controller
{
    use ClassifiesTraffic;

    public function __construct(private ClickHouseService $ch)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();
        $domainId = (int) $domain->id;

        // Lifetime window — how far back to look for first-touch + revenue.
        $days = max(7, min(730, (int) $request->query('days', 365)));
        $start = now()->subDays($days)->format('Y-m-d H:i:s');

        $sourceSql = $this->sourceClassificationSql();

        $rows = [];
        $currency = '';
        try {
            // First-touch source per visitor (earliest session's classified source).
            $firstTouch = "
                SELECT visitor_id, argMin(source, started_at) AS source
                FROM (
                    SELECT
                        visitor_id,
                        min(ts) AS started_at,
                        {$sourceSql} AS source
                    FROM (
                        SELECT
                            session_id, visitor_id, min(ts) AS ts,
                            anyIf(utm_source, utm_source != '') AS utm_source,
                            anyIf(referrer, referrer != '' AND type = 'pageview') AS referrer
                        FROM events
                        WHERE domain_id = {$domainId} AND ts >= '{$start}'
                        GROUP BY session_id, visitor_id
                    )
                    GROUP BY visitor_id, session_id
                )
                GROUP BY visitor_id
            ";

            // Revenue per visitor (dedup orders).
            $revPerVisitor = "
                SELECT visitor_id, sum(value) AS revenue, uniqExact(order_id) AS orders
                FROM (
                    SELECT visitor_id, order_id, argMax(value, ts) AS value
                    FROM conversions
                    WHERE domain_id = {$domainId} AND ts >= '{$start}'
                    GROUP BY visitor_id, order_id
                )
                GROUP BY visitor_id
            ";

            // Join: per source → visitors, paying visitors, revenue, avg LTV.
            $rows = $this->ch->select("
                SELECT
                    ft.source AS source,
                    uniq(ft.visitor_id) AS visitors,
                    countIf(c.revenue > 0) AS paying_visitors,
                    round(sum(c.revenue), 2) AS revenue,
                    sum(c.orders) AS orders
                FROM ({$firstTouch}) AS ft
                LEFT JOIN ({$revPerVisitor}) AS c ON ft.visitor_id = c.visitor_id
                GROUP BY source
                ORDER BY revenue DESC
                LIMIT 50
            ");

            $curRow = $this->ch->select("
                SELECT currency FROM conversions
                WHERE domain_id = {$domainId} AND currency != ''
                GROUP BY currency ORDER BY sum(value) DESC LIMIT 1
            ");
            $currency = $curRow[0]['currency'] ?? '';
        } catch (\Throwable $e) {
            report($e);
        }

        // Derived: avg LTV per visitor, conversion rate per source.
        $out = array_map(function ($r) {
            $visitors = (int) $r['visitors'];
            $revenue = (float) $r['revenue'];
            $paying = (int) $r['paying_visitors'];
            return [
                'source' => $r['source'] ?: '(direct)',
                'visitors' => $visitors,
                'paying_visitors' => $paying,
                'orders' => (int) $r['orders'],
                'revenue' => round($revenue, 2),
                'ltv' => $visitors > 0 ? round($revenue / $visitors, 2) : 0.0,
                'ltv_paying' => $paying > 0 ? round($revenue / $paying, 2) : 0.0,
                'conversion_rate' => $visitors > 0 ? round($paying / $visitors * 100, 2) : 0.0,
            ];
        }, $rows);

        return $this->success(['sources' => $out, 'currency' => $currency, 'days' => $days]);
    }
}
