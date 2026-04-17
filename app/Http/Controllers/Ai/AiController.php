<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeDomainJob;
use App\Models\AiReport;
use App\Models\AiSuggestion;
use App\Models\AudienceSegment;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class AiController extends Controller
{
    public function segments(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);

        return response()->json([
            'data' => AudienceSegment::where('domain_id', $domain->id)->get(),
        ]);
    }

    public function suggestions(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);

        return response()->json([
            'data' => AiSuggestion::where('domain_id', $domain->id)
                ->where('is_dismissed', false)
                ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->get(),
        ]);
    }

    public function analyze(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);

        // Check quota
        $plan = $request->user()->subscription?->plan;
        $maxRuns = $plan?->getLimit('max_analysis_runs_per_domain_per_month', 5) ?? 5;
        $quotaKey = "quota:{$domainId}:analysis:" . now()->format('Y-m');
        $used = (int) Redis::get($quotaKey);

        if ($maxRuns !== -1 && $used >= $maxRuns) {
            return response()->json([
                'message' => 'Monthly analysis quota reached.',
                'used' => $used,
                'limit' => $maxRuns,
            ], 429);
        }

        AnalyzeDomainJob::dispatch($domain->id)->onQueue('ai');

        return response()->json([
            'message' => 'Analysis queued.',
            'used' => $used,
            'limit' => $maxRuns,
        ], 202);
    }

    public function dismissSuggestion(Request $request, int $id): JsonResponse
    {
        $suggestion = AiSuggestion::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $suggestion->update(['is_dismissed' => true]);

        return response()->json(['message' => 'Suggestion dismissed.']);
    }

    public function quotaStatus(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);
        $plan = $request->user()->subscription?->plan;
        $maxRuns = $plan?->getLimit('max_analysis_runs_per_domain_per_month', 5) ?? 5;
        $quotaKey = "quota:{$domainId}:analysis:" . now()->format('Y-m');
        $used = (int) Redis::get($quotaKey);

        $lastReport = AiReport::where('domain_id', $domain->id)->latest('generated_at')->first();

        return response()->json([
            'used' => $used,
            'limit' => $maxRuns,
            'last_analyzed_at' => $lastReport?->generated_at,
        ]);
    }

    /**
     * Phase 2 stub — returns 503.
     */
    public function chat(): JsonResponse
    {
        return response()->json(['feature' => 'disabled', 'phase' => 2], 503);
    }

    private function authorizedDomain(Request $request, int $domainId): Domain
    {
        return Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
