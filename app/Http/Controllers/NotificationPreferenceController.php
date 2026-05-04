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
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', 'string'],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $item) {
            NotificationPreference::where('user_id', $request->user()->id)
                ->where('type', $item['type'])
                ->update([
                    'in_app' => $item['in_app'],
                    'email' => $item['email'],
                ]);
        }

        return $this->success(['message' => 'Preferences updated.']);
    }

    /**
     * One-click unsubscribe from email notifications via signed link.
     * GET /api/notifications/unsubscribe/{token}
     */
    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired unsubscribe link.');
        }

        $type = $request->query('type');
        $userId = (int) $request->query('user');

        NotificationPreference::where('user_id', $userId)
            ->where('type', $type)
            ->update(['email' => false]);

        return $this->success(["message" => "Unsubscribed from {$type} emails."]);
    }
}
