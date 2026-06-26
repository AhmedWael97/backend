<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates product features behind an active subscription.
 *
 * A user passes when they have an `activeSubscription()` — which is either a
 * paid plan OR a free trial still within its `current_period_end`. Once the
 * 30-day free trial lapses (and nothing paid replaces it), feature endpoints
 * return 402 with code `subscription_required` so the frontend can redirect to
 * billing. Account/billing/profile routes are intentionally NOT gated so the
 * user can still log in and subscribe.
 */
class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Unauthenticated requests are handled by auth:sanctum upstream.
        if (!$user) {
            return $next($request);
        }

        // Staff/admins are never gated by trial/subscription state.
        if (in_array($user->role, ['admin', 'super_admin', 'superadmin'], true)) {
            return $next($request);
        }

        // Active = paid plan OR free trial still within its period.
        // effectiveSubscription() also resolves the org owner's plan for team
        // members, so an agency's employees inherit the Agency subscription.
        if ($user->effectiveSubscription()) {
            return $next($request);
        }

        return response()->json([
            'statusCode' => 402,
            'statusText' => 'failed',
            'data' => [
                'message' => 'Your free trial has ended. Please subscribe to continue using EYE.',
                'code' => 'subscription_required',
            ],
        ], 402);
    }
}
