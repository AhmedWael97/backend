<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/analytics/{domainId}/retention
 *
 * Cohort retention grid: visitors are grouped by the period of their first visit
 * (the cohort), then we measure what fraction returned in each subsequent period.
 *
 * Query params:
 *   period = week|month   (default week)
 *   cohorts = 1..12       (number of cohort buckets, default 8)
 *
 * Response:
 *   { period, cohorts: [ { cohort: "2026-05-04", size: 120, retention: [100, 42.5, 18, …] } ] }
 *   retention[0] is always 100 (the cohort itself); retention[n] is the % active n periods later.
 */
class RetentionController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();
        $domainId = (int) $domain->id;

        $period = $request->query('period') === 'month' ? 'month' : 'week';
        $cohorts = max(1, min(12, (int) $request->query('cohorts', 8)));

        $bucket = $period === 'month' ? 'toStartOfMonth' : 'toMonday';
        if ($period === 'month') {
            $start = now()->startOfMonth()->subMonths($cohorts - 1)->format('Y-m-d H:i:s');
        } else {
            $start = now()->startOfWeek()->subWeeks($cohorts - 1)->format('Y-m-d H:i:s');
        }

        $rows = $this->ch->select("
            WITH first_seen AS (
                SELECT visitor_id, {$bucket}(min(ts)) AS cohort
                FROM events
                WHERE domain_id = {$domainId} AND ts >= '{$start}'
                GROUP BY visitor_id
            )
            SELECT
                toString(fs.cohort) AS cohort,
                dateDiff('{$period}', fs.cohort, {$bucket}(e.ts)) AS offset,
                uniq(e.visitor_id) AS visitors
            FROM events AS e
            INNER JOIN first_seen AS fs ON e.visitor_id = fs.visitor_id
            WHERE e.domain_id = {$domainId} AND e.ts >= '{$start}'
            GROUP BY cohort, offset
            HAVING offset >= 0
            ORDER BY cohort ASC, offset ASC
        ");

        // Pivot into a cohort → offset grid.
        $grid = [];
        foreach ($rows as $r) {
            $grid[(string) $r['cohort']][(int) $r['offset']] = (int) $r['visitors'];
        }

        $result = [];
        foreach ($grid as $cohort => $offsets) {
            $size = $offsets[0] ?? 0;
            if ($size === 0) {
                continue;
            }
            $maxOffset = max(array_keys($offsets));
            $retention = [];
            for ($o = 0; $o <= $maxOffset; $o++) {
                $retention[$o] = isset($offsets[$o]) ? round($offsets[$o] / $size * 100, 1) : 0.0;
            }
            $result[] = [
                'cohort' => $cohort,
                'size' => $size,
                'retention' => $retention,
            ];
        }

        return $this->success([
            'period' => $period,
            'cohorts' => $result,
        ]);
    }
}
