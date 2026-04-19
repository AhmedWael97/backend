<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * GET /api/billing
     * Returns the authenticated user's current subscription, usage, limits, payment history and available plans.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->with('plan')
            ->latest()
            ->first();

        $plan = $subscription?->plan;

        $domains = $user->domains()->count();
        $pageviews = 0; // ClickHouse query can be added here if needed
        $domainLimit = (int) ($plan?->getLimit('domains', 1) ?? 1);
        $pvLimit = (int) ($plan?->getLimit('pageviews_per_month', 10_000) ?? 10_000);

        $payments = Payment::where('user_id', $user->id)
            ->latest('paid_at')
            ->take(20)
            ->get(['id', 'amount', 'currency', 'status', 'paid_at', 'description']);

        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get();

        return $this->success([
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $plan,
                'status' => $subscription->status,
                'trial_ends_at' => null,
                'ends_at' => $subscription->cancelled_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
            'usage' => ['domains' => $domains, 'pageviews' => $pageviews],
            'limits' => ['domains' => $domainLimit, 'pageviews_per_month' => $pvLimit],
            'payments' => $payments,
            'plans' => $plans,
        ]);
    }

    /**
     * POST /api/billing/subscribe
     * Assigns a plan to the authenticated user.
     * In production this would initiate a payment checkout; here it directly creates/updates the subscription.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $user = $request->user();
        $plan = Plan::findOrFail($data['plan_id']);

        // Cancel any existing active subscription
        Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $this->success([
            'message' => "Switched to {$plan->name} plan.",
            'data' => $subscription->load('plan'),
        ]);
    }

    /**
     * POST /api/billing/cancel
     * Cancels the current subscription at period end.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            return $this->error('No active subscription found.', 404);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $this->success(['message' => 'Subscription cancelled at period end.']);
    }
}
