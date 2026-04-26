<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            ->get(['id', 'amount', 'currency', 'status', 'paid_at', 'reference', 'metadata', 'created_at']);

        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get();

        $bankTransferMethod = $this->getOrCreateBankTransferMethod();

        $paymentMethods = PaymentMethod::where('is_active', true)
            ->orderByRaw("CASE WHEN type = 'bank_transfer' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['id', 'name', 'name_ar', 'type', 'config']);

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
            'payment_methods' => $paymentMethods,
            'bank_transfer' => $bankTransferMethod ? [
                'id' => $bankTransferMethod->id,
                'name' => $bankTransferMethod->name,
                'name_ar' => $bankTransferMethod->name_ar,
                'details' => $bankTransferMethod->config ?? [],
            ] : null,
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
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'receipt' => ['nullable', 'file', 'image', 'max:5120'],
        ]);

        $user = $request->user();
        $plan = Plan::findOrFail($data['plan_id']);
        $fallbackMethod = $this->getOrCreateBankTransferMethod();
        $paymentMethodId = (int) ($data['payment_method_id'] ?? $fallbackMethod->id);

        $paymentMethod = PaymentMethod::where('id', $paymentMethodId)
            ->where('is_active', true)
            ->first();

        if (!$paymentMethod) {
            return $this->error('Selected payment method is not available.', 422);
        }

        $isBankTransfer = $paymentMethod->type === 'bank_transfer';
        if ($isBankTransfer && !$request->hasFile('receipt')) {
            return $this->error('Transaction receipt image is required for bank transfer.', 422, [
                'receipt' => ['Transaction receipt image is required for bank transfer.'],
            ]);
        }

        $receiptPath = null;
        $receiptUrl = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store("billing/receipts/{$user->id}", 'public');
            $receiptUrl = Storage::url($receiptPath);
        }

        // Cancel any existing active subscription
        Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_method_id' => $paymentMethod->id,
            'status' => $isBankTransfer ? 'paused' : 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'notes' => $isBankTransfer ? 'Pending bank transfer verification.' : null,
        ]);

        $paymentMetadata = [
            'payment_type' => $paymentMethod->type,
            'payment_method_name' => $paymentMethod->name,
            'bank_details' => $isBankTransfer ? ($paymentMethod->config ?? []) : null,
            'receipt_path' => $receiptPath,
            'receipt_url' => $receiptUrl,
        ];

        $payment = Payment::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => (float) ($plan->price_monthly ?? 0),
            'currency' => 'USD',
            'status' => $isBankTransfer ? 'pending' : 'paid',
            'reference' => $data['transaction_reference'] ?? null,
            'metadata' => $paymentMetadata,
            'paid_at' => $isBankTransfer ? null : now(),
        ]);

        $message = $isBankTransfer
            ? "Bank transfer request submitted for {$plan->name}. Your subscription will activate after payment verification."
            : "Switched to {$plan->name} plan.";

        return $this->success([
            'message' => $message,
            'data' => [
                'subscription' => $subscription->load('plan', 'paymentMethod'),
                'payment' => $payment,
            ],
        ]);
    }

    private function getOrCreateBankTransferMethod(): PaymentMethod
    {
        $existing = PaymentMethod::where('type', 'bank_transfer')->where('is_active', true)->first();
        if ($existing) {
            return $existing;
        }

        return PaymentMethod::create([
            'name' => 'Bank Transfer',
            'name_ar' => 'حوالة بنكية',
            'type' => 'bank_transfer',
            'is_active' => true,
            'config' => [
                'bank_name' => env('BANK_TRANSFER_BANK_NAME', 'Your Bank Name'),
                'account_name' => env('BANK_TRANSFER_ACCOUNT_NAME', 'EYE Analytics LLC'),
                'account_number' => env('BANK_TRANSFER_ACCOUNT_NUMBER', '0000000000'),
                'iban' => env('BANK_TRANSFER_IBAN', 'IBAN0000000000000000'),
                'swift' => env('BANK_TRANSFER_SWIFT', 'SWIFT000'),
            ],
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
