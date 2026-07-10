<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InsightFeedback;
use App\Services\InsightEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Deterministic insight engine — "what should I do" without an LLM.
 * Statistical detectors over ClickHouse, ranked by impact. Free + instant.
 */
class InsightController extends Controller
{
    public function __construct(private readonly InsightEngine $engine)
    {
    }

    /** GET /analytics/{domainId}/insights?page=overview */
    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::findOrFail($domainId);
        if (!$request->user()->canAccessDomain($domain)) {
            return $this->error('Forbidden.', 403);
        }

        $page = (string) $request->query('page', 'overview');

        // Cheap to recompute, but the ClickHouse scans aren't free — cache 15 min.
        $findings = Cache::remember(
            "insights:{$domain->id}:{$page}",
            900,
            fn () => match ($page) {
                'campaigns', 'channels', 'ltv' => $this->engine->marketing($domain->id),
                'funnels' => $this->engine->funnels($domain->id),
                'retention' => $this->engine->retention($domain->id),
                'experiments' => $this->engine->experiments($domain->id),
                'heatmaps' => $this->engine->heatmaps($domain->id),
                'seo' => $this->engine->seo($domain->id),
                default => $this->engine->overview($domain->id),
            }
        );

        return $this->success([
            'page' => $page,
            'findings' => $findings,
            'count' => count($findings),
        ]);
    }

    /** POST /analytics/{domainId}/insights/feedback — was this finding helpful? */
    public function feedback(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::findOrFail($domainId);
        if (!$request->user()->canAccessDomain($domain)) {
            return $this->error('Forbidden.', 403);
        }

        $data = $request->validate([
            'page' => ['required', 'string', 'max:40'],
            'kind' => ['required', 'string', 'max:60'],
            'helpful' => ['required', 'boolean'],
        ]);

        InsightFeedback::updateOrCreate(
            ['user_id' => $request->user()->id, 'domain_id' => $domain->id, 'page' => $data['page'], 'kind' => $data['kind']],
            ['helpful' => $data['helpful']]
        );

        return $this->success(['saved' => true]);
    }
}
