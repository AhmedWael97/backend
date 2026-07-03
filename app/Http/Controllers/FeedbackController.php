<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /** GET /feedback/status → whether this user already gave feedback. */
    public function status(Request $request): JsonResponse
    {
        $exists = Feedback::where('user_id', $request->user()->id)->exists();
        return $this->success(['submitted' => $exists]);
    }

    /** POST /feedback  { rating: 1-4, comment?: string } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:4'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        // One row per user — upsert so a re-submit updates rather than 500s.
        $feedback = Feedback::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['rating' => $data['rating'], 'comment' => $data['comment'] ?? null]
        );

        return $this->success($feedback, 201);
    }
}
