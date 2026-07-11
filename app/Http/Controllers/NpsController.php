<?php

namespace App\Http\Controllers;

use App\Models\NpsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NPS ("how likely to recommend", 0-10) — asked once, ~14 days into an
 * account with a connected domain, so the answer reflects real usage. Distinct
 * from FeedbackController's 1-4 CSAT (asked once, right after signup).
 */
class NpsController extends Controller
{
    private const MIN_ACCOUNT_AGE_DAYS = 14;

    /** GET /nps/eligibility */
    public function eligibility(Request $request): JsonResponse
    {
        $user = $request->user();

        $eligible = $user->nps_prompted_at === null
            && $user->created_at <= now()->subDays(self::MIN_ACCOUNT_AGE_DAYS)
            && $user->domains()->exists();

        return $this->success(['eligible' => $eligible]);
    }

    /** POST /nps  { score: 0-10, feedback?: string } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:0', 'max:10'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = $request->user();
        $response = NpsResponse::create([
            'user_id' => $user->id,
            'score' => $data['score'],
            'feedback' => $data['feedback'] ?? null,
        ]);
        $user->update(['nps_prompted_at' => now()]);

        return $this->success($response, 201);
    }

    /** POST /nps/dismiss — user closed it without answering; don't ask again. */
    public function dismiss(Request $request): JsonResponse
    {
        $request->user()->update(['nps_prompted_at' => now()]);
        return $this->success(['dismissed' => true]);
    }
}
