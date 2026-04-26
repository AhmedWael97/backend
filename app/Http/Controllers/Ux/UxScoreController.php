<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Jobs\ComputeUxScoreJob;
use App\Models\Domain;
use App\Models\UxScore;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxScoreController extends Controller
{
    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Ensure UX score is available and reasonably fresh for dashboard reads.
        $latest = UxScore::where('domain_id', $domain->id)->latest('calculated_at')->first();
        if (!$latest || $latest->calculated_at?->lt(now()->subMinutes(15))) {
            ComputeUxScoreJob::dispatchSync($domain->id);
        }

        $score = UxScore::where('domain_id', $domain->id)
            ->latest('calculated_at')
            ->first();

        if (!$score) {
            return $this->success([
                'score' => null,
                'breakdown' => null,
                'calculated_at' => null,
            ]);
        }

        return $this->success([
            'score' => $score->score,
            'breakdown' => $score->breakdown,
            'calculated_at' => $score->calculated_at,
        ]);
    }
}
