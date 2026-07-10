<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
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
                default => $this->engine->overview($domain->id),
            }
        );

        return $this->success([
            'page' => $page,
            'findings' => $findings,
            'count' => count($findings),
        ]);
    }
}
