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
    /**
     * @OA\Post(
     *   path="/api/v1/auth/login",
     *   summary="Login and receive a bearer token",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email",    type="string", format="email", example="user@example.com"),
     *       @OA\Property(property="password", type="string", format="password", example="secret")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Authenticated — returns token or 2FA challenge",
     *     @OA\JsonContent(
     *       @OA\Property(property="token", type="string"),
     *       @OA\Property(property="user",  type="object")
     *     )
     *   ),
     *   @OA\Response(response=422, description="Invalid credentials"),
     *   @OA\Response(response=403, description="Account suspended")
     * )
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (!$user->isActive()) {
            return $this->error('Your account is suspended.', 403);
        }

        // If 2FA is enabled, return a pending challenge instead of a token
        if ($user->totp_enabled) {
            $challenge = \Illuminate\Support\Str::random(40);
            cache()->put("totp_challenge:{$challenge}", $user->id, now()->addMinutes(5));

            return $this->success(['two_factor' => true, 'challenge' => $challenge]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'timezone', 'appearance', 'role', 'status', 'totp_enabled']),
            'token' => $token,
        ]);
    }
}
