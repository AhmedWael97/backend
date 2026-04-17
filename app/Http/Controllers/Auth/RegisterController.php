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

        event(new Registered($user));

        if (!config('app.email_verification_enabled', false)) {
            // Email verification disabled — auto-verify immediately
            $user->markEmailAsVerified();
        } else {
            $user->sendEmailVerificationNotification();
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->refresh()->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'timezone', 'appearance', 'role', 'status']),
            'token' => $token,
        ], 201);
    }
}
