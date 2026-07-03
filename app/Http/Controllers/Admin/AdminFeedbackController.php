<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFeedbackController extends Controller
{
    /** GET /admin/feedback — list + rating breakdown. */
    public function index(Request $request): JsonResponse
    {
        $items = Feedback::with('user:id,name,email')
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'rating' => $f->rating,
                'comment' => $f->comment,
                'created_at' => $f->created_at,
                'user_name' => $f->user?->name,
                'user_email' => $f->user?->email,
            ]);

        $total = Feedback::count();
        $avg = $total ? round((float) Feedback::avg('rating'), 2) : 0;
        $breakdown = [];
        foreach ([1, 2, 3, 4] as $r) {
            $breakdown[$r] = Feedback::where('rating', $r)->count();
        }

        return $this->success([
            'items' => $items,
            'stats' => ['total' => $total, 'avg_rating' => $avg, 'breakdown' => $breakdown],
        ]);
    }
}
