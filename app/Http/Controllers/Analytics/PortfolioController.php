<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AdSpend;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-site portfolio for users managing many domains.
 *
 *   GET /api/v1/portfolio/overview?days=30  — per-domain KPI table + deltas vs prior period
 *   GET /api/v1/portfolio/triage?days=30    — ranked "needs attention" issues across all sites
 *
 * Built for the 20-sites-at-once manager: one screen instead of switching the
 * domain selector 20 times.
 */
class PortfolioController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        [$rows, $range] = $this->buildRows($request);

        $totals = [
            'visitors' => array_sum(array_column(array_column($rows, 'current'), 'visitors')),
            'sessions' => array_sum(array_column(array_column($rows, 'current'), 'sessions')),
            'revenue' => round(array_sum(array_column(array_column($rows, 'current'), 'revenue')), 2),
            'spend' => round(array_sum(array_column(array_column($rows, 'current'), 'spend')), 2),
            'orders' => array_sum(array_column(array_column($rows, 'current'), 'orders')),
        ];
        $totals['roas'] = $totals['spend'] > 0 ? round($totals['revenue'] / $totals['spend'], 2) : null;

        return $this->success([
            'domains' => $rows,
            'totals' => $totals,
            'range' => $range,
        ]);
    }

    public function triage(Request $request): JsonResponse
    {
        [$rows] = $this->buildRows($request);

        $issues = [];
        foreach ($rows as $row) {
            $cur = $row['current'];
            $prev = $row['prior'];
            $d = ['domain_id' => $row['domain_id'], 'domain' => $row['domain']];

            // Wasted ad spend — ROAS below break-even with meaningful spend.
            if ($cur['spend'] >= 1 && $cur['roas'] !== null && $cur['roas'] < 1) {
                $issues[] = $d + [
                    'type' => 'low_roas',
                    'severity' => $cur['roas'] < 0.5 ? 'high' : 'medium',
                    'title' => 'ROAS below break-even',
                    'detail' => "ROAS {$cur['roas']}× — spent " . round($cur['spend'], 2) . ", made " . round($cur['revenue'], 2) . ".",
                    'impact' => round($cur['spend'] - $cur['revenue'], 2),
                ];
            }

            // Revenue drop vs prior period.
            if ($prev['revenue'] >= 50 && $cur['revenue'] < $prev['revenue'] * 0.8) {
                $drop = round((1 - $cur['revenue'] / $prev['revenue']) * 100, 1);
                $issues[] = $d + [
                    'type' => 'revenue_drop',
                    'severity' => $drop >= 40 ? 'high' : 'medium',
                    'title' => "Revenue down {$drop}%",
                    'detail' => "Revenue fell from " . round($prev['revenue'], 2) . " to " . round($cur['revenue'], 2) . " vs the prior period.",
                    'impact' => round($prev['revenue'] - $cur['revenue'], 2),
                ];
            }

            // Traffic drop vs prior period.
            if ($prev['sessions'] >= 100 && $cur['sessions'] < $prev['sessions'] * 0.7) {
                $drop = round((1 - $cur['sessions'] / $prev['sessions']) * 100, 1);
                $issues[] = $d + [
                    'type' => 'traffic_drop',
                    'severity' => $drop >= 50 ? 'high' : 'medium',
                    'title' => "Traffic down {$drop}%",
                    'detail' => "Sessions fell from {$prev['sessions']} to {$cur['sessions']} vs the prior period.",
                    'impact' => ($prev['sessions'] - $cur['sessions']) * 0.5, // weight below revenue
                ];
            }

            // Error spike.
            if ($cur['errors'] >= 20 && $cur['errors'] > $prev['errors'] * 1.5) {
                $issues[] = $d + [
                    'type' => 'error_spike',
                    'severity' => 'medium',
                    'title' => "{$cur['errors']} JS errors",
                    'detail' => "JS errors rose from {$prev['errors']} to {$cur['errors']} vs the prior period.",
                    'impact' => $cur['errors'] * 0.2,
                ];
            }

            // Untagged campaigns / no attribution — revenue but no tracked spend.
            if ($cur['revenue'] >= 50 && $cur['spend'] == 0) {
                $issues[] = $d + [
                    'type' => 'no_spend_data',
                    'severity' => 'low',
                    'title' => 'No ad spend recorded',
                    'detail' => "This site made " . round($cur['revenue'], 2) . " but has no spend entered — ROAS can't be computed.",
                    'impact' => 1,
                ];
            }
        }

        // Rank by impact (money at stake), highest first.
        usort($issues, fn($a, $b) => ($b['impact'] <=> $a['impact']));

        return $this->success(['issues' => array_slice($issues, 0, 50)]);
    }

    /**
     * @return array{0: array<int, array>, 1: array}
     */
    private function buildRows(Request $request): array
    {
        $user = $request->user();
        $days = max(1, min(365, (int) $request->query('days', 30)));

        $end = now();
        $start = (clone $end)->subDays($days);
        $priorEnd = (clone $start);
        $priorStart = (clone $priorEnd)->subDays($days);

        $domains = $user->isSuperAdmin()
            ? Domain::query()->get(['id', 'domain'])
            : $user->domains()->get(['id', 'domain']);

        $idToName = [];
        foreach ($domains as $d) {
            $idToName[(int) $d->id] = $d->domain;
        }
        $ids = array_keys($idToName);

        if (empty($ids)) {
            return [[], ['days' => $days, 'start' => $start->toDateString(), 'end' => $end->toDateString()]];
        }

        $current = $this->periodMetrics($ids, $start, $end);
        $prior = $this->periodMetrics($ids, $priorStart, $priorEnd);

        $rows = [];
        foreach ($ids as $id) {
            $cur = $current[$id];
            $prev = $prior[$id];
            $rows[] = [
                'domain_id' => $id,
                'domain' => $idToName[$id],
                'current' => $cur,
                'prior' => $prev,
                'deltas' => [
                    'visitors' => $this->pctDelta($prev['visitors'], $cur['visitors']),
                    'sessions' => $this->pctDelta($prev['sessions'], $cur['sessions']),
                    'revenue' => $this->pctDelta($prev['revenue'], $cur['revenue']),
                    'orders' => $this->pctDelta($prev['orders'], $cur['orders']),
                ],
            ];
        }

        // Default sort: most revenue first.
        usort($rows, fn($a, $b) => $b['current']['revenue'] <=> $a['current']['revenue']);

        return [$rows, ['days' => $days, 'start' => $start->toDateString(), 'end' => $end->toDateString()]];
    }

    /**
     * Per-domain metric map for a period. Keys are guaranteed present for every id.
     *
     * @return array<int, array{sessions:int,visitors:int,errors:int,bounce_rate:float,revenue:float,orders:int,spend:float,conversion_rate:float,roas:?float}>
     */
    private function periodMetrics(array $ids, Carbon $start, Carbon $end): array
    {
        $inList = implode(',', array_map('intval', $ids));
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = [
                'sessions' => 0, 'visitors' => 0, 'errors' => 0, 'bounce_rate' => 0.0,
                'revenue' => 0.0, 'orders' => 0, 'spend' => 0.0,
                'conversion_rate' => 0.0, 'roas' => null,
            ];
        }

        // Traffic + errors + bounce (per-session subquery for bounce).
        try {
            $rows = $this->ch->select("
                SELECT
                    domain_id,
                    uniq(session_id) AS sessions,
                    uniq(visitor_id) AS visitors,
                    sum(errors)      AS errors,
                    countIf(pv = 1)  AS bounced
                FROM (
                    SELECT domain_id, session_id, any(visitor_id) AS visitor_id,
                           countIf(type = 'pageview') AS pv,
                           countIf(type = 'js_error') AS errors
                    FROM events
                    WHERE domain_id IN ({$inList}) AND ts >= '{$startStr}' AND ts < '{$endStr}'
                    GROUP BY domain_id, session_id
                )
                GROUP BY domain_id
            ");
            foreach ($rows as $r) {
                $id = (int) $r['domain_id'];
                $sessions = (int) $r['sessions'];
                $out[$id]['sessions'] = $sessions;
                $out[$id]['visitors'] = (int) $r['visitors'];
                $out[$id]['errors'] = (int) $r['errors'];
                $out[$id]['bounce_rate'] = $sessions > 0 ? round((int) $r['bounced'] / $sessions * 100, 1) : 0.0;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Revenue + orders from conversions (dedup by order_id).
        try {
            $rows = $this->ch->select("
                SELECT domain_id, uniqExact(order_id) AS orders, round(sum(value), 2) AS revenue
                FROM (
                    SELECT domain_id, order_id, argMax(value, ts) AS value
                    FROM conversions
                    WHERE domain_id IN ({$inList}) AND ts >= '{$startStr}' AND ts < '{$endStr}'
                    GROUP BY domain_id, order_id
                )
                GROUP BY domain_id
            ");
            foreach ($rows as $r) {
                $id = (int) $r['domain_id'];
                $out[$id]['orders'] = (int) $r['orders'];
                $out[$id]['revenue'] = (float) $r['revenue'];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Spend from PostgreSQL ad_spend.
        try {
            $spend = AdSpend::whereIn('domain_id', $ids)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('domain_id, SUM(spend) AS spend')
                ->groupBy('domain_id')
                ->pluck('spend', 'domain_id');
            foreach ($spend as $domainId => $value) {
                $out[(int) $domainId]['spend'] = (float) $value;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Derived metrics.
        foreach ($out as $id => $m) {
            $out[$id]['conversion_rate'] = $m['sessions'] > 0 ? round($m['orders'] / $m['sessions'] * 100, 2) : 0.0;
            $out[$id]['roas'] = $m['spend'] > 0 ? round($m['revenue'] / $m['spend'], 2) : null;
        }

        return $out;
    }

    private function pctDelta(float $prev, float $cur): ?float
    {
        if ($prev <= 0) {
            return null; // can't compute a % change from zero baseline
        }
        return round(($cur - $prev) / $prev * 100, 1);
    }
}
