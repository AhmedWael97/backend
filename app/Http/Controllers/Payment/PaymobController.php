<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paymob payment gateway integration.
 *
 * Classic three-step flow:
 *   1. POST /api/auth/tokens            → obtain Paymob auth token
 *   2. POST /api/ecommerce/orders       → register order, get order_id
 *   3. POST /api/acceptance/payment_keys → obtain payment_key (iframe token)
 *   4. Frontend redirects user to:
 *      https://accept.paymob.com/api/acceptance/iframes/{IFRAME_ID}?payment_token={payment_key}
 *
 * Webhook (HMAC-verified) at POST /api/v1/billing/paymob/webhook
 *   confirms payment and activates the subscription.
 *
 * Required .env keys:
 *   PAYMOB_API_KEY          — your Paymob API key
 *   PAYMOB_INTEGRATION_ID   — card payment integration ID
 *   PAYMOB_IFRAME_ID        — hosted iframe ID
 *   PAYMOB_HMAC_SECRET      — HMAC secret for webhook verification
 */
class PaymobController extends Controller
{
    private const BASE_URL = 'https://accept.paymob.com/api';

    // ── Step 1-3: Initiate payment ─────────────────────────────────────────

    /**
     * POST /api/v1/billing/paymob/initiate
     *
     * Authenticated — receives plan_id, calls Paymob, returns iframe URL.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $apiKey = (string) config('services.paymob.api_key');
        $integrationId = (int) config('services.paymob.integration_id');
        $iframeId = (string) config('services.paymob.iframe_id');

        if (!$apiKey || !$integrationId || !$iframeId) {
            return $this->error('Paymob is not configured. Please contact support.', 503);
        }

        $user = $request->user();
        $plan = Plan::findOrFail($request->input('plan_id'));

        // ── 1. Auth token ──────────────────────────────────────────────────
        $authRes = Http::timeout(15)->post(self::BASE_URL . '/auth/tokens', [
            'api_key' => $apiKey,
        ]);

        if (!$authRes->successful()) {
            Log::error('Paymob auth failed', ['body' => $authRes->body()]);
            return $this->error('Payment gateway authentication failed.', 502);
        }

        $authToken = $authRes->json('token');
        if (!$authToken) {
            return $this->error('Invalid response from payment gateway.', 502);
        }

        // ── 2. Create order ────────────────────────────────────────────────
        $amountCents = (int) round((float) ($plan->price_monthly ?? 0) * 100);

        $orderRes = Http::timeout(15)->post(self::BASE_URL . '/ecommerce/orders', [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => $amountCents,
            'currency' => 'EGP',
            'items' => [
                [
                    'name' => $plan->name . ' Plan — Monthly',
                    'amount_cents' => $amountCents,
                    'description' => $plan->description ?? '',
                    'quantity' => 1,
                ],
            ],
            'merchant_order_id' => 'eye_' . $user->id . '_' . $plan->id . '_' . time(),
        ]);

        if (!$orderRes->successful()) {
            Log::error('Paymob create order failed', ['body' => $orderRes->body()]);
            return $this->error('Could not create payment order.', 502);
        }

        $orderId = $orderRes->json('id');
        if (!$orderId) {
            return $this->error('Invalid order response from payment gateway.', 502);
        }

        // ── 3. Payment key ─────────────────────────────────────────────────
        $keyRes = Http::timeout(15)->post(self::BASE_URL . '/acceptance/payment_keys', [
            'auth_token' => $authToken,
            'amount_cents' => $amountCents,
            'expiration' => 3600,
            'order_id' => $orderId,
            'currency' => 'EGP',
            'integration_id' => $integrationId,
            'billing_data' => [
                'apartment' => 'N/A',
                'email' => $user->email,
                'floor' => 'N/A',
                'first_name' => $this->firstName($user->name),
                'street' => 'N/A',
                'building' => 'N/A',
                'phone_number' => $user->phone ?? '+20000000000',
                'shipping_method' => 'PKG',
                'postal_code' => '00000',
                'city' => 'Cairo',
                'country' => 'EGY',
                'last_name' => $this->lastName($user->name),
                'state' => 'Cairo',
            ],
        ]);

        if (!$keyRes->successful()) {
            Log::error('Paymob payment key failed', ['body' => $keyRes->body()]);
            return $this->error('Could not obtain payment key.', 502);
        }

        $paymentKey = $keyRes->json('token');
        if (!$paymentKey) {
            return $this->error('Invalid payment key from gateway.', 502);
        }

        // Persist a pending payment record for reconciliation in the webhook
        $paymobMethod = $this->getOrCreatePaymobMethod();
        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_method_id' => $paymobMethod->id,
            'amount' => $plan->price_monthly ?? 0,
            'currency' => 'EGP',
            'status' => 'pending',
            'reference' => (string) $orderId,
            'metadata' => [
                'paymob_order_id' => $orderId,
                'paymob_iframe_id' => $iframeId,
                'plan_id' => $plan->id,
            ],
        ]);

        $iframeUrl = "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$paymentKey}";

        return $this->success([
            'iframe_url' => $iframeUrl,
            'order_id' => $orderId,
            'payment_id' => $payment->id,
            'amount' => $amountCents,
            'currency' => 'EGP',
        ]);
    }

    // ── Webhook: payment confirmation ──────────────────────────────────────

    /**
     * POST /api/v1/billing/paymob/webhook
     *
     * Public endpoint — called by Paymob.
     * Verifies HMAC signature then activates/rejects the subscription.
     */
    public function webhook(Request $request): \Illuminate\Http\Response
    {
        $hmacSecret = (string) config('services.paymob.hmac_secret');
        $payload = $request->all();

        // ── HMAC verification ──────────────────────────────────────────────
        if ($hmacSecret) {
            $receivedHmac = $request->query('hmac', '');
            $computed = $this->computeHmac($payload, $hmacSecret);

            if (!hash_equals($computed, strtolower((string) $receivedHmac))) {
                Log::warning('Paymob webhook HMAC mismatch', ['received' => $receivedHmac]);
                return response('', 403);
            }
        }

        $obj = $payload['obj'] ?? [];
        $orderId = (string) ($obj['order']['id'] ?? '');
        $success = (bool) ($obj['success'] ?? false);
        $amountCents = (int) ($obj['amount_cents'] ?? 0);
        $transactionId = (string) ($obj['id'] ?? '');

        $payment = Payment::where('reference', $orderId)
            ->where('status', 'pending')
            ->first();

        if (!$payment) {
            return response('', 200); // Already processed or unknown
        }

        if ($success) {
            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'metadata' => array_merge((array) ($payment->metadata ?? []), [
                    'paymob_transaction_id' => $transactionId,
                    'amount_cents' => $amountCents,
                ]),
            ]);

            // Cancel any existing active subscription
            Subscription::where('user_id', $payment->user_id)
                ->whereIn('status', ['active', 'trialing'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            Subscription::create([
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'payment_method_id' => $payment->payment_method_id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);

            // Link subscription to payment
            $subscription = Subscription::where('user_id', $payment->user_id)
                ->latest()->first();
            if ($subscription) {
                $payment->update(['subscription_id' => $subscription->id]);
            }
        } else {
            $payment->update(['status' => 'failed']);
        }

        return response('', 200);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute the Paymob HMAC-SHA512 signature over the concatenated fields
     * as documented at https://docs.paymob.com/docs/transaction-webhooks
     */
    private function computeHmac(array $payload, string $secret): string
    {
        $obj = $payload['obj'] ?? [];

        $fields = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order.id',
            'owner',
            'pending',
            'source_data.pan',
            'source_data.sub_type',
            'source_data.type',
            'success',
        ];

        $parts = [];
        foreach ($fields as $field) {
            $keys = explode('.', $field);
            $value = $obj;
            foreach ($keys as $k) {
                $value = $value[$k] ?? '';
            }
            $parts[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return hash_hmac('sha512', implode('', $parts), $secret);
    }

    private function firstName(string $name): string
    {
        $parts = explode(' ', trim($name), 2);
        return $parts[0] ?: 'User';
    }

    private function lastName(string $name): string
    {
        $parts = explode(' ', trim($name), 2);
        return $parts[1] ?? 'N/A';
    }

    private function getOrCreatePaymobMethod(): PaymentMethod
    {
        $existing = PaymentMethod::where('type', 'paymob')->where('is_active', true)->first();
        if ($existing)
            return $existing;

        return PaymentMethod::create([
            'name' => 'Paymob',
            'name_ar' => 'باي موب',
            'type' => 'paymob',
            'is_active' => true,
            'config' => [],
        ]);
    }
}
