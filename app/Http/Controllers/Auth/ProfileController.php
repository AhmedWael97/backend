<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->success([
            'user' => $request->user()->only([
                'id',
                'name',
                'email',
                'locale',
                'timezone',
                'appearance',
                'role',
                'status',
                'totp_enabled',
                'email_verified_at',
                'created_at',
            ]),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->validated());

        return $this->success([
            'message' => 'Profile updated.',
            'user' => $request->user()->fresh()->only([
                'id',
                'name',
                'email',
                'locale',
                'timezone',
                'appearance',
                'role',
                'status',
            ]),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->input('password')),
        ]);

        // Revoke all existing tokens so other sessions are logged out
        $request->user()->tokens()->delete();

        $token = $request->user()->createToken('api')->plainTextToken;

        return $this->success([
            'message' => 'Password changed. All other sessions have been revoked.',
            'token' => $token,
        ]);
    }

    /**
     * Regenerate the user's API key.
     */
    public function regenerateApiKey(Request $request): JsonResponse
    {
        $newKey = \Illuminate\Support\Str::random(64);
        $request->user()->update(['api_key' => $newKey]);

        return $this->success([
            'message' => 'API key regenerated.',
            'api_key' => $newKey,
        ]);
    }

    /**
     * GET /api/profile/api-key — return the user's current API key (masked except last 8 chars).
     */
    public function apiKey(Request $request): JsonResponse
    {
        // Re-fetch to get the hidden field
        $user = \App\Models\User::find($request->user()->id);
        $key = $user->api_key;

        return $this->success(['api_key' => $key]);
    }

    /**
     * GET /api/profile/sessions — list all active Sanctum tokens for the user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_active' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
                'is_current' => $t->id === $request->user()->currentAccessToken()->id,
            ]);

        return $this->success($tokens);
    }

    /**
     * DELETE /api/profile/sessions/{tokenId} — revoke a specific session token.
     */
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $request->user()->tokens()
            ->where('id', $tokenId)
            ->delete();

        return $this->success(['message' => 'Session revoked.']);
    }

    /**
     * Update locale and/or appearance preferences.
     * PATCH /api/profile/preferences
     */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', 'in:ar,en'],
            'appearance' => ['sometimes', 'in:light,dark,system'],
        ]);

        $request->user()->update($data);

        return $this->success([
            'message' => 'Preferences updated.',
            'user' => $request->user()->fresh()->only([
                'id',
                'name',
                'email',
                'locale',
                'timezone',
                'appearance',
            ]),
        ]);
    }

    /**
     * Delete the authenticated user's account (GDPR).
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        $user->delete();

        return $this->success(['message' => 'Account deleted.']);
    }
}
