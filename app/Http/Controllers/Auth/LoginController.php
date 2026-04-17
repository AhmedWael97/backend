<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (!$user->isActive()) {
            return response()->json(['message' => 'Your account is suspended.'], 403);
        }

        // If 2FA is enabled, return a pending challenge instead of a token
        if ($user->totp_enabled) {
            // Store a short-lived pending challenge in cache
            $challenge = \Illuminate\Support\Str::random(40);
            cache()->put("totp_challenge:{$challenge}", $user->id, now()->addMinutes(5));

            return response()->json([
                'two_factor' => true,
                'challenge' => $challenge,
            ], 200);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'timezone', 'appearance', 'role', 'status', 'totp_enabled']),
            'token' => $token,
        ]);
    }
}
