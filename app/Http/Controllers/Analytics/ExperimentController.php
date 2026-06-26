<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Experiment;
use App\Services\ClickHouseService;
use App\Services\ConvertService;
use App\Services\GrowthBookService;
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
    public function __construct(
        private ClickHouseService $ch,
        private GrowthBookService $growthbook,
        private ConvertService $convert,
    ) {
    }

    // ── Convert.com integration ───────────────────────────────────────────────
    // Convert owns assignment + stats; EYE overlays revenue (matched by the
    // experience key to our exposure events, same join as GrowthBook).

    public function convertStatus(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);
        return $this->success(['connected' => $this->convert->isConfigured()]);
    }

    public function convertList(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);
        $experiments = $this->convert->listExperiments();
        $out = array_map(fn($e) => [
            'id' => (string) ($e['id'] ?? ''),
            'name' => $e['name'] ?? ('Experience ' . ($e['id'] ?? '')),
            'key' => $e['key'] ?? ($e['name'] ?? null),
            'status' => $e['status'] ?? null,
            'variations' => array_map(
                fn($v) => $v['key'] ?? $v['name'] ?? (string) ($v['id'] ?? ''),
                $e['variations'] ?? $e['variants'] ?? []
            ),
        ], $experiments);

        return $this->success([
            'connected' => $this->convert->isConfigured(),
            'experiments' => $out,
        ]);
    }

    public function convertResults(Request $request, int $domainId, string $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $domainId = (int) $domain->id;

        $experiment = $this->convert->experiment($id);
        $results = $this->convert->results($id);
        // Match Convert experience to our exposure events by key (or name fallback).
        $key = (string) ($experiment['key'] ?? $experiment['name'] ?? '');
        $revenue = $key !== '' ? $this->revenueByVariant($domainId, $key) : [];

        return $this->success([
            'experiment' => $experiment,
            'convert_results' => $results,
            'revenue' => $revenue,
        ]);
    }

    // ── GrowthBook integration ────────────────────────────────────────────────
    // GrowthBook owns assignment + rigorous stats; EYE overlays its own revenue
    // (matched by the experiment's trackingKey to our exposure events).

    public function growthbookStatus(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);
        return $this->success([
            'connected' => $this->growthbook->isConfigured(),
            'host' => rtrim((string) config('services.growthbook.api_host'), '/') ?: null,
        ]);
    }

    public function growthbookList(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);
        $experiments = $this->growthbook->listExperiments($request->query('project'));
        $out = array_map(fn($e) => [
            'id' => $e['id'] ?? null,
            'name' => $e['name'] ?? ($e['trackingKey'] ?? 'Experiment'),
            'trackingKey' => $e['trackingKey'] ?? null,
            'status' => $e['status'] ?? null,
            'variations' => array_map(fn($v) => $v['key'] ?? $v['name'] ?? '', $e['variations'] ?? []),
        ], $experiments);

        return $this->success([
            'connected' => $this->growthbook->isConfigured(),
            'experiments' => $out,
        ]);
    }

    public function growthbookResults(Request $request, int $domainId, string $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $domainId = (int) $domain->id;

        $experiment = $this->growthbook->experiment($id);
        $results = $this->growthbook->results($id);
        $key = $experiment['trackingKey'] ?? '';
        $revenue = $key !== '' ? $this->revenueByVariant($domainId, $key) : [];

        return $this->success([
            'experiment' => $experiment,
            'growthbook_results' => $results,
            'revenue' => $revenue,
        ]);
    }

    /**
     * EYE revenue overlay per variant for an experiment trackingKey, using the
     * exposure events (custom_events name='experiment') joined to conversions.
     *
     * @return array<string, array{converters:int,orders:int,revenue:float}>
     */
    private function revenueByVariant(int $domainId, string $key): array
    {
        $safeKey = addslashes($key);
        $expCte = "
            SELECT visitor_id, argMin(JSONExtractString(props, 'variant'), ts) AS variant
            FROM custom_events
            WHERE domain_id = {$domainId}
              AND name = 'experiment'
              AND JSONExtractString(props, 'exp') = '{$safeKey}'
            GROUP BY visitor_id
        ";

        $out = [];
        try {
            $rows = $this->ch->select("
                SELECT
                    e.variant            AS variant,
                    uniq(c.visitor_id)   AS converters,
                    uniqExact(c.order_id) AS orders,
                    round(sum(c.value), 2) AS revenue
                FROM ({$expCte}) AS e
                INNER JOIN (
                    SELECT order_id, any(visitor_id) AS visitor_id, argMax(value, ts) AS value
                    FROM conversions WHERE domain_id = {$domainId} GROUP BY order_id
                ) AS c ON e.visitor_id = c.visitor_id
                GROUP BY variant
            ");
            foreach ($rows as $r) {
                $out[(string) $r['variant']] = [
                    'converters' => (int) $r['converters'],
                    'orders' => (int) $r['orders'],
                    'revenue' => (float) $r['revenue'],
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return $out;
    }

    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        return $this->success(
            Experiment::where('domain_id', $domain->id)->with('variations')->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $data = $this->validateBuilder($request, true);

        $experiment = Experiment::create([
            'domain_id' => $domain->id,
            'key' => $this->uniqueKey($domain->id, $data['name']),
            'name' => $data['name'],
            'type' => $data['type'],
            'target_url' => $data['target_url'],
            'goal_type' => $data['goal_type'],
            'goal_value' => $data['goal_value'] ?? null,
            'status' => 'draft',
            'is_active' => true,
            'variants' => [],
        ]);
        $this->syncVariations($experiment, $data['variations']);

        return $this->success($experiment->load('variations'), 201);
    }

    public function update(Request $request, int $domainId, int $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $experiment = Experiment::where('domain_id', $domain->id)->where('id', $id)->firstOrFail();
        $data = $this->validateBuilder($request, false);

        $experiment->update(array_filter([
            'name' => $data['name'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'goal_type' => $data['goal_type'] ?? null,
            'goal_value' => $data['goal_value'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn($v) => $v !== null));

        if (isset($data['variations'])) {
            $this->syncVariations($experiment, $data['variations']);
        }

        return $this->success($experiment->fresh()->load('variations'));
    }

    public function destroy(Request $request, int $domainId, int $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        Experiment::where('domain_id', $domain->id)->where('id', $id)->firstOrFail()->delete();
        return $this->success(['deleted' => true]);
    }

    /**
     * Public — the tracker (eye-ab.js) fetches running experiments to apply.
     * GET /api/v1/experiments/active?t=token
     */
    public function active(Request $request): JsonResponse
    {
        $cors = ['Access-Control-Allow-Origin' => '*'];
        $token = $request->query('t') ?? $request->header('X-Eye-Token');
        $domain = $token
            ? Domain::where(function ($q) use ($token) {
                $q->where('script_token', $token)->orWhere('previous_script_token', $token);
            })->where('active', true)->first()
            : null;

        if (!$domain) {
            return $this->success(['experiments' => []])->withHeaders($cors);
        }

        $experiments = Experiment::where('domain_id', $domain->id)
            ->where('status', 'running')->with('variations')->get();

        $out = $experiments->map(fn($e) => [
            'key' => $e->key,
            'type' => $e->type,
            'target_url' => $e->target_url,
            'variations' => $e->variations->map(fn($v) => [
                'key' => $v->vkey,
                'weight' => $v->weight,
                'is_control' => $v->is_control,
                'js' => $v->js_code,
                'css' => $v->css_code,
                'redirect' => $v->redirect_url,
            ])->values(),
        ])->values();

        return $this->success(['experiments' => $out])->withHeaders($cors);
    }

    public function results(Request $request, int $domainId, int $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $domainId = (int) $domain->id;
        $experiment = Experiment::where('domain_id', $domainId)->where('id', $id)->with('variations')->firstOrFail();

        $safeKey = addslashes($experiment->key);

        // Stable assignment: each visitor's variant = the one at their first exposure.
        $expCte = "
            SELECT visitor_id, argMin(JSONExtractString(props, 'variant'), ts) AS variant
            FROM custom_events
            WHERE domain_id = {$domainId}
              AND name = 'experiment'
              AND JSONExtractString(props, 'exp') = '{$safeKey}'
            GROUP BY visitor_id
        ";

        // Goal: which exposed visitors "converted" (purchase | event | url).
        $goalVal = addslashes((string) $experiment->goal_value);
        if ($experiment->goal_type === 'event' && $goalVal !== '') {
            $goalCte = "SELECT DISTINCT visitor_id FROM custom_events WHERE domain_id = {$domainId} AND name = '{$goalVal}'";
        } elseif ($experiment->goal_type === 'url' && $goalVal !== '') {
            $goalCte = "SELECT DISTINCT visitor_id FROM events WHERE domain_id = {$domainId} AND type = 'pageview' AND url LIKE '%{$goalVal}%'";
        } else {
            $goalCte = "SELECT DISTINCT visitor_id FROM conversions WHERE domain_id = {$domainId}";
        }

        $visitorsByVariant = [];
        $convByVariant = [];
        try {
            foreach ($this->ch->select("SELECT variant, uniq(visitor_id) AS visitors FROM ({$expCte}) GROUP BY variant") as $r) {
                $visitorsByVariant[(string) $r['variant']] = (int) $r['visitors'];
            }
            foreach ($this->ch->select("
                SELECT e.variant AS variant, uniq(e.visitor_id) AS converters
                FROM ({$expCte}) AS e
                INNER JOIN ({$goalCte}) AS g ON e.visitor_id = g.visitor_id
                GROUP BY variant
            ") as $r) {
                $convByVariant[(string) $r['variant']] = (int) $r['converters'];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Optional revenue overlay (reuses the conversions join).
        $revenue = [];
        try {
            $revenue = $this->revenueByVariant($domainId, $experiment->key);
        } catch (\Throwable $e) {
            report($e);
        }

        // Build per-variation rows; control is the flagged variation.
        $control = $experiment->variations->firstWhere('is_control', true)
            ?? $experiment->variations->first();
        $controlKey = $control?->vkey;
        $controlVisitors = $controlKey ? ($visitorsByVariant[$controlKey] ?? 0) : 0;
        $controlConv = $controlKey ? ($convByVariant[$controlKey] ?? 0) : 0;
        $controlRate = $controlVisitors > 0 ? $controlConv / $controlVisitors : 0.0;

        $results = [];
        foreach ($experiment->variations as $v) {
            $visitors = $visitorsByVariant[$v->vkey] ?? 0;
            $converters = $convByVariant[$v->vkey] ?? 0;
            $rate = $visitors > 0 ? $converters / $visitors : 0.0;
            $isControl = (bool) $v->is_control;
            $z = !$isControl ? $this->zScore($controlConv, $controlVisitors, $converters, $visitors) : null;

            $results[] = [
                'key' => $v->vkey,
                'name' => $v->name,
                'weight' => $v->weight,
                'is_control' => $isControl,
                'visitors' => $visitors,
                'converters' => $converters,
                'conversion_rate' => round($rate * 100, 2),
                'uplift' => (!$isControl && $controlRate > 0) ? round(($rate - $controlRate) / $controlRate * 100, 1) : null,
                'revenue' => round((float) ($revenue[$v->vkey]['revenue'] ?? 0), 2),
                'z' => $z !== null ? round($z, 2) : null,
                'significant' => $z !== null ? abs($z) >= 1.96 : null,
            ];
        }

        return $this->success(['experiment' => $experiment, 'results' => $results]);
    }

    // ── Builder helpers ────────────────────────────────────────────────────────

    private function validateBuilder(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'name' => [$req, 'string', 'max:200'],
            'type' => [$req, 'in:ab,split_url'],
            'target_url' => [$req, 'string', 'max:2048'],
            'goal_type' => [$req, 'in:purchase,event,url'],
            'goal_value' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,running,paused'],
            'variations' => [$req, 'array', 'min:1', 'max:10'],
            'variations.*.name' => ['required_with:variations', 'string', 'max:120'],
            'variations.*.weight' => ['required_with:variations', 'integer', 'min:0', 'max:100'],
            'variations.*.js_code' => ['nullable', 'string'],
            'variations.*.css_code' => ['nullable', 'string'],
            'variations.*.redirect_url' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    private function syncVariations(Experiment $experiment, array $variations): void
    {
        $experiment->variations()->delete();
        foreach (array_values($variations) as $i => $v) {
            $experiment->variations()->create([
                'vkey' => $i === 0 ? 'control' : 'v' . $i,
                'name' => $v['name'],
                'weight' => (int) $v['weight'],
                'is_control' => $i === 0,
                'js_code' => $v['js_code'] ?? null,
                'css_code' => $v['css_code'] ?? null,
                'redirect_url' => $v['redirect_url'] ?? null,
                'sort' => $i,
            ]);
        }
    }

    private function uniqueKey(int $domainId, string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'exp';
        $key = $base;
        $i = 1;
        while (Experiment::where('domain_id', $domainId)->where('key', $key)->exists()) {
            $key = $base . '-' . (++$i);
        }
        return $key;
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
            ->accessibleBy($user)
            ->firstOrFail();
    }
}
