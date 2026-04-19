<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
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
