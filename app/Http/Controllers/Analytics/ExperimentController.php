<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Experiment;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A/B test experiments. Exposures are recorded client-side via EYE.experiment()
 * as a `experiment` custom event ({ exp: key, variant }) — so no special
 * ingestion is needed. Results join exposed visitors against the conversions
 * table for revenue-aware comparison + a two-proportion significance test.
 *
 *   GET    /api/v1/analytics/{domainId}/experiments
 *   POST   /api/v1/analytics/{domainId}/experiments
 *   DELETE /api/v1/analytics/{domainId}/experiments/{id}
 *   GET    /api/v1/analytics/{domainId}/experiments/{id}/results
 */
class ExperimentController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        return $this->success(
            Experiment::where('domain_id', $domain->id)->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:200'],
            'variants' => ['required', 'array', 'min:2', 'max:6'],
            'variants.*' => ['required', 'string', 'max:64'],
        ]);

        if (Experiment::where('domain_id', $domain->id)->where('key', $data['key'])->exists()) {
            return $this->error('An experiment with that key already exists.', 422);
        }

        $experiment = Experiment::create([
            'domain_id' => $domain->id,
            'key' => $data['key'],
            'name' => $data['name'],
            'variants' => array_values($data['variants']),
            'is_active' => true,
        ]);

        return $this->success($experiment, 201);
    }

    public function destroy(Request $request, int $domainId, int $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        Experiment::where('domain_id', $domain->id)->where('id', $id)->firstOrFail()->delete();
        return $this->success(['deleted' => true]);
    }

    public function results(Request $request, int $domainId, int $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $domainId = (int) $domain->id;
        $experiment = Experiment::where('domain_id', $domainId)->where('id', $id)->firstOrFail();

        $safeKey = addslashes($experiment->key);

        // Each visitor's variant = the one at their FIRST exposure (stable assignment).
        $expCte = "
            SELECT visitor_id, argMin(JSONExtractString(props, 'variant'), ts) AS variant
            FROM custom_events
            WHERE domain_id = {$domainId}
              AND name = 'experiment'
              AND JSONExtractString(props, 'exp') = '{$safeKey}'
            GROUP BY visitor_id
        ";

        $visRows = $this->ch->select("SELECT variant, uniq(visitor_id) AS visitors FROM ({$expCte}) GROUP BY variant");
        $visitorsByVariant = [];
        foreach ($visRows as $r) {
            $visitorsByVariant[(string) $r['variant']] = (int) $r['visitors'];
        }

        // Conversions by exposed visitor → orders / revenue / converters per variant.
        $convByVariant = [];
        $currency = '';
        try {
            $convRows = $this->ch->select("
                SELECT
                    e.variant            AS variant,
                    uniq(c.visitor_id)   AS converters,
                    uniqExact(c.order_id) AS orders,
                    round(sum(c.value), 2) AS revenue
                FROM ({$expCte}) AS e
                INNER JOIN (
                    SELECT order_id, any(visitor_id) AS visitor_id, argMax(value, ts) AS value
                    FROM conversions
                    WHERE domain_id = {$domainId}
                    GROUP BY order_id
                ) AS c ON e.visitor_id = c.visitor_id
                GROUP BY variant
            ");
            foreach ($convRows as $r) {
                $convByVariant[(string) $r['variant']] = [
                    'converters' => (int) $r['converters'],
                    'orders' => (int) $r['orders'],
                    'revenue' => (float) $r['revenue'],
                ];
            }

            $curRow = $this->ch->select("
                SELECT currency FROM conversions
                WHERE domain_id = {$domainId} AND currency != ''
                GROUP BY currency ORDER BY sum(value) DESC LIMIT 1
            ");
            $currency = $curRow[0]['currency'] ?? '';
        } catch (\Throwable $e) {
            report($e); // conversions table may not be migrated yet
        }

        // Build per-variant rows in the experiment's declared order (first = control).
        $variants = $experiment->variants ?? [];
        $control = $variants[0] ?? null;
        $controlVisitors = $control !== null ? ($visitorsByVariant[$control] ?? 0) : 0;
        $controlConv = $control !== null ? ($convByVariant[$control]['converters'] ?? 0) : 0;
        $controlRate = $controlVisitors > 0 ? $controlConv / $controlVisitors : 0.0;

        $results = [];
        foreach ($variants as $variant) {
            $visitors = $visitorsByVariant[$variant] ?? 0;
            $conv = $convByVariant[$variant] ?? ['converters' => 0, 'orders' => 0, 'revenue' => 0.0];
            $rate = $visitors > 0 ? $conv['converters'] / $visitors : 0.0;
            $isControl = $variant === $control;

            $uplift = (!$isControl && $controlRate > 0) ? round(($rate - $controlRate) / $controlRate * 100, 1) : null;
            $z = (!$isControl) ? $this->zScore($controlConv, $controlVisitors, $conv['converters'], $visitors) : null;

            $results[] = [
                'variant' => $variant,
                'is_control' => $isControl,
                'visitors' => $visitors,
                'converters' => $conv['converters'],
                'orders' => $conv['orders'],
                'revenue' => round($conv['revenue'], 2),
                'conversion_rate' => round($rate * 100, 2),
                'revenue_per_visitor' => $visitors > 0 ? round($conv['revenue'] / $visitors, 2) : 0.0,
                'uplift' => $uplift,
                'z' => $z !== null ? round($z, 2) : null,
                'significant' => $z !== null ? abs($z) >= 1.96 : null, // 95% confidence
            ];
        }

        return $this->success([
            'experiment' => $experiment,
            'currency' => $currency,
            'results' => $results,
        ]);
    }

    /** Two-proportion z-test between control and a variant. */
    private function zScore(int $c1, int $n1, int $c2, int $n2): ?float
    {
        if ($n1 <= 0 || $n2 <= 0) {
            return null;
        }
        $p1 = $c1 / $n1;
        $p2 = $c2 / $n2;
        $pooled = ($c1 + $c2) / ($n1 + $n2);
        $se = sqrt($pooled * (1 - $pooled) * (1 / $n1 + 1 / $n2));
        if ($se <= 0.0) {
            return null;
        }
        return ($p2 - $p1) / $se;
    }

    private function authorizeDomain(Request $request, int $domainId): Domain
    {
        $user = $request->user();
        return Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();
    }
}
