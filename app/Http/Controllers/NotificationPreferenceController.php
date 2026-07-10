<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $prefs = NotificationPreference::where('user_id', $request->user()->id)
            ->get(['type', 'in_app', 'email']);

        return $this->success($prefs);
    }

    public function update(Request $request): JsonResponse
    {
        // The frontend PATCHes a bare array, not {preferences: [...]}.
        $items = $request->has('preferences') ? $request->input('preferences') : $request->all();

        $data = validator(['preferences' => $items], [
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', 'string'],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ])->validate();

        foreach ($data['preferences'] as $item) {
            // Rows are never pre-seeded, so this must upsert — update-only meant
            // toggling a preference for the first time silently did nothing.
            NotificationPreference::updateOrCreate(
                ['user_id' => $request->user()->id, 'type' => $item['type']],
                ['in_app' => $item['in_app'], 'email' => $item['email']]
            );
        }

        return $this->success(['message' => 'Preferences updated.']);
    }

    /**
     * One-click unsubscribe from email notifications via signed link.
     * GET /api/notifications/unsubscribe?user=&type=&signature=&expires=
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired unsubscribe link.');
        }

        $type = $request->query('type');
        $userId = (int) $request->query('user');

        NotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'type' => $type],
            ['email' => false]
        );

        return $this->success(["message" => "Unsubscribed from {$type} emails."]);
    }
}
