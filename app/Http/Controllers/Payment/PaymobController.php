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
    private const DEFAULT_BASE_URL = 'https://accept.paymob.com/api';

    /**
     * Resolve the active Paymob credentials, honouring the test/production
     * environment selected in the super-admin dashboard.
     *
     * config shape (payment_methods.config):
     *   { "mode": "test"|"production",
     *     "test":       { api_key, secret_key, public_key, integration_id, iframe_id, hmac_secret, base_url? },
     *     "production":  { … } }
     *
     * Resolution priority per field: active-mode DB config → flat/legacy DB
     * config → PAYMOB_* env. So old flat configs and env still work.
     *
     * @return array{method: ?PaymentMethod, mode: string, base_url: string, api_key: string, integration_id: int, iframe_id: string, hmac_secret: string}
     */
    private function resolvePaymobConfig(): array
    {
        $method = PaymentMethod::where('type', 'paymob')->where('is_active', true)->first();
        $cfg = (array) ($method?->config ?? []);

        $mode = in_array(($cfg['mode'] ?? null), ['test', 'production'], true) ? $cfg['mode'] : 'test';
        $modeCfg = is_array($cfg[$mode] ?? null) ? $cfg[$mode] : [];

        $pick = fn(string $key, string $envKey) => $modeCfg[$key] ?? $cfg[$key] ?? config("services.paymob.{$envKey}");

        $baseUrl = (string) ($modeCfg['base_url'] ?? $cfg['base_url'] ?? config('services.paymob.base_url') ?: self::DEFAULT_BASE_URL);

        return [
            'method' => $method,
            'mode' => $mode,
            'base_url' => rtrim($baseUrl, '/'),
            'api_key' => (string) $pick('api_key', 'api_key'),
            'integration_id' => (int) $pick('integration_id', 'integration_id'),
            'iframe_id' => (string) $pick('iframe_id', 'iframe_id'),
            'hmac_secret' => (string) $pick('hmac_secret', 'hmac_secret'),
        ];
    }

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

        // Resolve credentials for the active environment (test/production) set in
        // the super-admin dashboard; .env is the fallback.
        $cfg = $this->resolvePaymobConfig();
        $paymobMethod = $cfg['method'];

        if (!$paymobMethod) {
            return $this->error('Paymob payment is not enabled. Please contact support.', 503);
        }

        $apiKey = $cfg['api_key'];
        $integrationId = $cfg['integration_id'];
        $iframeId = $cfg['iframe_id'];
        $baseUrl = $cfg['base_url'];

        if (!$apiKey || !$integrationId || !$iframeId) {
            return $this->error('Paymob is not fully configured. Please contact support.', 503);
        }

        $user = $request->user();
        $plan = Plan::findOrFail($request->input('plan_id'));

        // ── 1. Auth token ──────────────────────────────────────────────────
        $authRes = Http::timeout(15)->post($baseUrl . '/auth/tokens', [
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

        $orderRes = Http::timeout(15)->post($baseUrl . '/ecommerce/orders', [
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
        $keyRes = Http::timeout(15)->post($baseUrl . '/acceptance/payment_keys', [
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
                'paymob_mode' => $cfg['mode'],
                'plan_id' => $plan->id,
            ],
        ]);

        $iframeUrl = "{$baseUrl}/acceptance/iframes/{$iframeId}?payment_token={$paymentKey}";

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
        // Resolve HMAC secret for the active environment (DB first, .env fallback).
        $hmacSecret = $this->resolvePaymobConfig()['hmac_secret'];

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
        $isRefunded = (bool) ($obj['is_refunded'] ?? false);
        $isVoided = (bool) ($obj['is_voided'] ?? false);
        $amountCents = (int) ($obj['amount_cents'] ?? 0);
        $transactionId = (string) ($obj['id'] ?? '');

        // Look up regardless of status — refund and void webhooks arrive AFTER
        // the payment is already 'paid', so filtering by status='pending' would
        // make us silently swallow them.
        $payment = Payment::where('reference', $orderId)->lockForUpdate()->first();

        if (!$payment) {
            Log::info('Paymob webhook: unknown order', ['order_id' => $orderId]);
            return response('', 200);
        }

        // ── Amount-cents reconciliation ────────────────────────────────────
        // Defense against a replayed/forged webhook from a smaller test charge.
        // The Payment was created with `amount = plan->price_monthly` (decimal
        // dollars/EGP). Convert to cents and compare with a 1-unit tolerance
        // for floating-point rounding.
        $expectedCents = (int) round((float) $payment->amount * 100);
        if ($amountCents > 0 && abs($amountCents - $expectedCents) > 1) {
            Log::warning('Paymob webhook: amount mismatch — rejecting', [
                'order_id' => $orderId,
                'expected_cents' => $expectedCents,
                'received_cents' => $amountCents,
            ]);
            return response('', 200);
        }

        // ── Refund / void: reverse the linked subscription ─────────────────
        if ($isRefunded || $isVoided) {
            if ($payment->status !== 'refunded' && $payment->status !== 'voided') {
                $payment->update([
                    'status' => $isRefunded ? 'refunded' : 'voided',
                    'metadata' => array_merge((array) ($payment->metadata ?? []), [
                        'paymob_transaction_id' => $transactionId,
                        $isRefunded ? 'refunded_at' : 'voided_at' => now()->toIso8601String(),
                    ]),
                ]);

                if ($payment->subscription_id) {
                    Subscription::where('id', $payment->subscription_id)
                        ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                }

                // Reverse any AI tokens granted on this payment
                $metadata = (array) ($payment->metadata ?? []);
                if (($metadata['type'] ?? null) === 'ai_tokens' && $payment->user) {
                    $tokens = (int) ($metadata['tokens'] ?? 0);
                    if ($tokens > 0) {
                        $payment->user->update([
                            'ai_tokens' => max(0, ((int) $payment->user->ai_tokens) - $tokens),
                        ]);
                    }
                }
            }
            return response('', 200);
        }

        // ── Idempotency: a successful webhook delivered twice for the same
        // order must not double-activate. We only activate when the payment
        // is still pending.
        if ($payment->status !== 'pending') {
            return response('', 200);
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

            // Cancel any existing active subscription only AFTER the new one is
            // ready, so the user is never briefly without an active plan.
            $oldActive = Subscription::where('user_id', $payment->user_id)
                ->whereIn('status', ['active', 'trialing'])
                ->get();

            $newSubscription = Subscription::create([
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'payment_method_id' => $payment->payment_method_id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);

            // Link subscription to payment via explicit FK (avoids races with
            // concurrent webhooks creating overlapping subscriptions).
            $payment->update(['subscription_id' => $newSubscription->id]);

            foreach ($oldActive as $sub) {
                if ($sub->id !== $newSubscription->id) {
                    $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                }
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
        // By this point initiate() has already verified an active Paymob method exists.
        // We fetch it again here to use as the FK on the Payment record.
        return PaymentMethod::where('type', 'paymob')->where('is_active', true)->firstOrFail();
    }
}
