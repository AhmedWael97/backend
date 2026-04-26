<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    /**
     * @OA\Post(
     *   path="/api/v1/auth/register",
     *   summary="Register a new user account",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","password","password_confirmation"},
     *       @OA\Property(property="name",                  type="string",  example="John Doe"),
     *       @OA\Property(property="email",                 type="string",  format="email"),
     *       @OA\Property(property="password",              type="string",  format="password"),
     *       @OA\Property(property="password_confirmation", type="string",  format="password"),
     *       @OA\Property(property="locale",                type="string",  example="en"),
     *       @OA\Property(property="timezone",              type="string",  example="UTC")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Account created — returns token"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // hashed via cast
            'api_key' => Str::random(64),
            'locale' => $request->input('locale', 'en'),
            'timezone' => $request->input('timezone', 'UTC'),
            'appearance' => 'system',
            'role' => 'user',
            'status' => 'active',
        ]);

        // Attach free plan subscription
        $freePlan = Plan::where('slug', 'free')->first();
        if ($freePlan) {
            Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'current_period_start' => now(),
            ]);
        }

        // Only dispatch verification flow when explicitly enabled.
        // This avoids SMTP/network latency (and 504s) when verification is paused.
        if (!config('app.email_verification_enabled', false)) {
            $user->markEmailAsVerified();
        } else {
            event(new Registered($user));
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'user' => $user->refresh()->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'timezone', 'appearance', 'role', 'status']),
            'token' => $token,
        ], 201);
    }
}
