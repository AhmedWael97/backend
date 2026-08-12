<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuestionnaireResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminQuestionnaireController extends Controller
{
    /**
     * Rows where the visitor bounced before answering anything — landed on
     * step 1, autosave fired once with every real field still null/empty.
     * Not a useful "response" to show or count.
     */
    private function scopeAnswered($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('role')
                ->orWhereNotNull('sites_managed')
                ->orWhereRaw('json_array_length(languages) > 0')
                ->orWhereRaw('json_array_length(features) > 0')
                ->orWhereRaw('json_array_length(domains) > 0');
        });
    }

    /** GET /admin/onboarding-quiz — every "get started" questionnaire response with at least one real answer. */
    public function index(Request $request): JsonResponse
    {
        $items = $this->scopeAnswered(QuestionnaireResponse::with(['user:id,name,email', 'planAssigned:id,name']))
            ->latest('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'visitor_id' => $r->visitor_id,
                'completed' => $r->completed,
                'step_reached' => $r->step_reached,
                'role' => $r->role,
                'sites_managed' => $r->sites_managed,
                'languages' => $r->languages,
                'features' => $r->features,
                'domains' => $r->domains,
                'plan_assigned' => $r->planAssigned?->name,
                'user_name' => $r->user?->name,
                'user_email' => $r->user?->email,
                'created_at' => $r->created_at,
            ]);

        // Most-requested feature, across all responses — the single most useful
        // number for prioritizing the roadmap.
        $featureCounts = [];
        foreach (QuestionnaireResponse::whereNotNull('features')->pluck('features') as $features) {
            foreach ((array) $features as $f) {
                $featureCounts[$f] = ($featureCounts[$f] ?? 0) + 1;
            }
        }
        arsort($featureCounts);

        return $this->success([
            'items' => $items,
            'total' => $this->scopeAnswered(QuestionnaireResponse::query())->count(),
            'top_features' => array_slice($featureCounts, 0, 10, true),
        ]);
    }
}
