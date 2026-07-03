<?php

namespace App\Http\Controllers\Analytics;

use App\Models\Domain;
use App\Services\AnalyticsQueryService;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Period-over-period comparison of the headline KPIs.
 *
 * GET /api/analytics/{domainId}/compare
 *   ?preset=today_vs_yesterday | week_vs_last_week | month_vs_last_month
 *   — or a fully custom pair —
 *   ?a_from=YYYY-MM-DD&a_to=YYYY-MM-DD&b_from=YYYY-MM-DD&b_to=YYYY-MM-DD
 *
 * Returns each metric for both periods plus the % change, trend direction, and
 * whether that change is "good" for the business (up is good for everything
 * except bounce rate) so the UI can render green/red at-a-glance labels.
 */
class CompareController extends BaseAnalyticsController
{
    public function __construct(
        private readonly AnalyticsQueryService $analytics,
        private readonly ClickHouseService $clickhouse,
    ) {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        [$a, $b] = $this->resolvePeriods($request);

        $ma = $this->metricsFor($domain->id, $a['from'], $a['to']);
        $mb = $this->metricsFor($domain->id, $b['from'], $b['to']);

        // [key, label, higherIsBetter, format]
        $defs = [
            ['visitors',     'Visitors',           true,  'number'],
            ['sessions',     'Sessions',           true,  'number'],
            ['pageviews',    'Page views',         true,  'number'],
            ['engagements',  'Engagements',        true,  'number'],
            ['signups',      'Sign-ups',           true,  'number'],
            ['avg_duration', 'Avg. time on site',  true,  'duration'],
            ['bounce_rate',  'Bounce rate',        false, 'percent'],
        ];

        $metrics = [];
        foreach ($defs as [$key, $label, $higherBetter, $format]) {
            $av = $ma[$key];
            $bv = $mb[$key];
            $changePct = $bv > 0
                ? round((($av - $bv) / $bv) * 100, 1)
                : ($av > 0 ? 100.0 : 0.0);
            $trend = $av > $bv ? 'up' : ($av < $bv ? 'down' : 'flat');
            $good = $trend === 'flat' ? null : (($trend === 'up') === $higherBetter);

            $metrics[] = [
                'key'        => $key,
                'label'      => $label,
                'a'          => $av,
                'b'          => $bv,
                'change_pct' => $changePct,
                'trend'      => $trend,
                'good'       => $good,
                'format'     => $format,
            ];
        }

        return $this->success([
            'period_a' => $a,
            'period_b' => $b,
            'metrics'  => $metrics,
        ]);
    }

    /**
     * @return array{visitors:int,sessions:int,pageviews:int,engagements:int,signups:int,avg_duration:int,bounce_rate:float}
     */
    private function metricsFor(int $domainId, string $from, string $to): array
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        $s = $this->analytics->stats($domainId, $start, $end, 'day')['summary'];

        $rows = $this->clickhouse->select("
            SELECT
                countIf(type IN ('click','rage_click','dead_click','scroll_depth','custom')) AS engagements,
                countIf(type = 'identify') AS signups
            FROM events
            WHERE domain_id = {$domainId}
              AND ts >= '" . $start->format('Y-m-d H:i:s') . "'
              AND ts <  '" . $end->format('Y-m-d H:i:s') . "'
        ");
        $extra = $rows[0] ?? ['engagements' => 0, 'signups' => 0];

        return [
            'visitors'     => (int) $s['unique_visitors'],
            'sessions'     => (int) $s['sessions'],
            'pageviews'    => (int) $s['pageviews'],
            'engagements'  => (int) ($extra['engagements'] ?? 0),
            'signups'      => (int) ($extra['signups'] ?? 0),
            'avg_duration' => (int) $s['avg_duration'],
            'bounce_rate'  => (float) $s['bounce_rate'],
        ];
    }

    /**
     * Resolve the two comparison windows from either a preset or a custom pair.
     *
     * @return array{0: array{label:string,from:string,to:string}, 1: array{label:string,from:string,to:string}}
     */
    private function resolvePeriods(Request $request): array
    {
        $fmt = 'Y-m-d H:i:s';
        $now = now();

        // A fully custom pair wins if all four dates are supplied.
        if ($request->filled('a_from') && $request->filled('a_to') && $request->filled('b_from') && $request->filled('b_to')) {
            return [
                [
                    'label' => 'Period A',
                    'from'  => Carbon::parse($request->query('a_from'))->startOfDay()->format($fmt),
                    'to'    => Carbon::parse($request->query('a_to'))->endOfDay()->format($fmt),
                ],
                [
                    'label' => 'Period B',
                    'from'  => Carbon::parse($request->query('b_from'))->startOfDay()->format($fmt),
                    'to'    => Carbon::parse($request->query('b_to'))->endOfDay()->format($fmt),
                ],
            ];
        }

        switch ($request->query('preset', 'today_vs_yesterday')) {
            case 'week_vs_last_week':
                $aFrom = (clone $now)->startOfWeek();
                $aTo   = clone $now;
                $bFrom = (clone $aFrom)->subWeek();
                $bTo   = (clone $aTo)->subWeek();
                $la = 'This week';
                $lb = 'Last week';
                break;

            case 'month_vs_last_month':
                $aFrom = (clone $now)->startOfMonth();
                $aTo   = clone $now;
                $bFrom = (clone $aFrom)->subMonthNoOverflow();
                $bTo   = (clone $aTo)->subMonthNoOverflow();
                $la = 'This month';
                $lb = 'Last month';
                break;

            case 'today_vs_yesterday':
            default:
                // Day-to-date: today so far vs the same window yesterday.
                $aFrom = (clone $now)->startOfDay();
                $aTo   = clone $now;
                $bFrom = (clone $now)->subDay()->startOfDay();
                $bTo   = (clone $now)->subDay();
                $la = 'Today';
                $lb = 'Yesterday';
                break;
        }

        return [
            ['label' => $la, 'from' => $aFrom->format($fmt), 'to' => $aTo->format($fmt)],
            ['label' => $lb, 'from' => $bFrom->format($fmt), 'to' => $bTo->format($fmt)],
        ];
    }
}
