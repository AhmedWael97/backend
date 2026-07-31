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
use Illuminate\Support\Str;

/**
 * Paddle payment gateway integration (Paddle Billing — merchant of record).
 *
 * Unlike Paymob (server builds an iframe URL), Paddle Billing checkout runs
 * client-side via Paddle.js: the frontend calls initiate() to get a client
 * token + the plan's Paddle price ID + a correlation reference, then opens
 * Paddle's own checkout overlay directly in the browser. Paddle handles
 * payment, tax/VAT, and localized pricing itself; we only find out the
 * actual charged amount/currency from the webhook.
 *
 * Same "one-time charge grants 1 month" model as Paymob (EYE has no real
 * auto-recurring billing on either gateway — the user/re-invoice repeats the
 * initiate() flow monthly). So Paddle prices should be created as ONE-TIME
 * prices in the Paddle dashboard, not recurring/subscription prices — using
 * a recurring price would make Paddle auto-bill and fire subscription
 * lifecycle events this controller does not handle.
 *
 * Not yet handled (flagging honestly rather than half-building): promo
 * codes (Paddle has its own separate Discounts system, not wired to EYE's
 * PromoCode model), and refund/chargeback reversal (Paddle's Adjustments
 * webhook shape wasn't available to verify against a real sandbox account
 * at build time).
 *
 * Required .env keys (fallback — normally set via super-admin Payment Methods):
 *   PADDLE_CLIENT_TOKEN   — client-side token (safe to expose to the browser)
 *   PADDLE_API_KEY        — server-side API key (used only by test())
 *   PADDLE_WEBHOOK_SECRET — webhook signing secret
 *   PADDLE_ENVIRONMENT    — 'sandbox' or 'production'
 */
class PaddleController extends Controller
{
    private const SANDBOX_BASE_URL = 'https://sandbox-api.paddle.com';
    private const LIVE_BASE_URL = 'https://api.paddle.com';

    /**
     * Resolve the active Paddle credentials, honouring the test/production
     * environment selected in the super-admin dashboard.
     *
     * config shape (payment_methods.config):
     *   { "mode": "test"|"production",
     *     "test":       { client_token, api_key, webhook_secret, prices: {"<plan_id>": "pri_..."} },
     *     "production":  { … } }
     *
     * Resolution priority per field: active-mode DB config → PADDLE_* env.
     * "test" mode maps to Paddle's sandbox environment/API host.
     *
     * @return array{method: ?PaymentMethod, mode: string, environment: string, base_url: string, client_token: string, api_key: string, webhook_secret: string, prices: array<string,string>, missing: array<int,string>}
     */
    private function resolvePaddleConfig(): array
    {
        $method = PaymentMethod::where('type', 'paddle')->where('is_active', true)->first();
        $cfg = (array) ($method?->config ?? []);

        $selectedMode = in_array(($cfg['mode'] ?? null), ['test', 'production'], true) ? $cfg['mode'] : 'test';

        $resolveMode = function (string $mode) use ($cfg) {
            $modeCfg = is_array($cfg[$mode] ?? null) ? $cfg[$mode] : [];
            $pick = function (string $key) use ($modeCfg) {
                $v = $modeCfg[$key] ?? config("services.paddle.{$key}");
                return is_string($v) ? trim($v) : $v;
            };

            $clientToken = (string) $pick('client_token');
            $apiKey = (string) $pick('api_key');
            $webhookSecret = (string) $pick('webhook_secret');
            $prices = is_array($modeCfg['prices'] ?? null) ? $modeCfg['prices'] : [];

            $missing = [];
            if ($clientToken === '') {
                $missing[] = 'client_token';
            }
            if ($apiKey === '') {
                $missing[] = 'api_key';
            }
            if ($webhookSecret === '') {
                $missing[] = 'webhook_secret';
            }

            return [
                'mode' => $mode,
                'environment' => $mode === 'production' ? 'production' : 'sandbox',
                'base_url' => $mode === 'production' ? self::LIVE_BASE_URL : self::SANDBOX_BASE_URL,
                'client_token' => $clientToken,
                'api_key' => $apiKey,
                'webhook_secret' => $webhookSecret,
                'prices' => $prices,
                'missing' => $missing,
            ];
        };

        // Prefer the selected mode, but rescue the other one if it's fully
        // configured and the selected one isn't (same as Paymob's fallback).
        $resolved = $resolveMode($selectedMode);
        if (!empty($resolved['missing'])) {
            $other = $selectedMode === 'test' ? 'production' : 'test';
            $otherResolved = $resolveMode($other);
            if (empty($otherResolved['missing'])) {
                Log::warning('Paddle: selected mode incomplete — falling back to the populated environment', [
                    'selected' => $selectedMode,
                    'used' => $other,
                ]);
                $resolved = $otherResolved;
            }
        }

        return array_merge(['method' => $method], $resolved);
    }

    /**
     * POST /api/v1/admin/payment-methods/paddle/test  (super-admin)
     *
     * Verifies the SAVED active-mode API key by calling Paddle's own API.
     */
    public function test(Request $request): JsonResponse
    {
        $cfg = $this->resolvePaddleConfig();
        $mode = $cfg['mode'];

        if (!$cfg['method']) {
            return $this->success(['ok' => false, 'mode' => $mode, 'message' => 'No active Paddle payment method. Save the credentials and enable Paddle first.']);
        }
        if (!empty($cfg['missing'])) {
            return $this->success(['ok' => false, 'mode' => $mode, 'message' => "Missing required field(s) for {$mode} mode: " . implode(', ', $cfg['missing']) . '.', 'missing' => $cfg['missing']]);
        }

        try {
            $res = Http::withToken($cfg['api_key'])->timeout(15)->get($cfg['base_url'] . '/event-types');
            if (!$res->successful()) {
                return $this->success(['ok' => false, 'mode' => $mode, 'message' => "Paddle rejected the API key (HTTP {$res->status()}). Double-check the API key for {$mode} mode."]);
            }
            return $this->success(['ok' => true, 'mode' => $mode, 'message' => "Connection OK — Paddle accepted the {$mode} API key ({$cfg['environment']})."]);
        } catch (\Throwable $e) {
            report($e);
            return $this->success(['ok' => false, 'mode' => $mode, 'message' => 'Could not reach Paddle: ' . $e->getMessage()]);
        }
    }

    // ── Initiate checkout ───────────────────────────────────────────────────

    /**
     * POST /api/v1/billing/paddle/initiate
     *
     * Authenticated — receives plan_id, returns everything the frontend needs
     * to open Paddle.js's checkout overlay directly (client token, price ID,
     * a correlation reference). No server-to-server Paddle call happens here;
     * Paddle itself confirms payment via the webhook.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $cfg = $this->resolvePaddleConfig();
        $paddleMethod = $cfg['method'];

        if (!$paddleMethod) {
            return $this->error('Paddle payment is not enabled. Please contact support.', 503);
        }
        if (!empty($cfg['missing'])) {
            Log::warning('Paddle not fully configured', ['mode' => $cfg['mode'], 'missing' => $cfg['missing']]);
            return $this->error('Paddle is not fully configured. Please contact support.', 503);
        }

        $user = $request->user();
        $plan = Plan::findOrFail($request->input('plan_id'));

        $priceId = $cfg['prices'][(string) $plan->id] ?? null;
        if (!$priceId) {
            Log::warning('Paddle: no price configured for plan', ['plan_id' => $plan->id, 'mode' => $cfg['mode']]);
            return $this->error('Paddle is not configured for this plan yet. Please contact support.', 503);
        }

        $reference = 'eye_paddle_' . $user->id . '_' . $plan->id . '_' . time() . '_' . Str::random(6);

        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_method_id' => $paddleMethod->id,
            // Recorded for our own bookkeeping — Paddle may charge a different
            // localized amount/currency; the webhook doesn't reconcile against
            // this the way the Paymob one reconciles amount_cents.
            'amount' => $plan->price_monthly,
            'currency' => 'USD',
            'status' => 'pending',
            'reference' => $reference,
            'metadata' => [
                'paddle_price_id' => $priceId,
                'paddle_mode' => $cfg['mode'],
                'plan_id' => $plan->id,
            ],
        ]);

        return $this->success([
            'client_token' => $cfg['client_token'],
            'environment' => $cfg['environment'],
            'price_id' => $priceId,
            'reference' => $reference,
            'payment_id' => $payment->id,
            'customer_email' => $user->email,
        ]);
    }

    // ── Webhook: payment confirmation ────────────────────────────────────────

    /**
     * POST /api/v1/billing/paddle/webhook
     *
     * Public endpoint — called by Paddle. Verifies the Paddle-Signature
     * header then activates the subscription on transaction.completed.
     */
    public function webhook(Request $request): \Illuminate\Http\Response
    {
        $webhookSecret = $this->resolvePaddleConfig()['webhook_secret'];
        $rawBody = $request->getContent();

        if ($webhookSecret) {
            $header = (string) $request->header('Paddle-Signature', '');
            if (!$this->verifySignature($header, $rawBody, $webhookSecret)) {
                Log::warning('Paddle webhook signature mismatch');
                return response('', 403);
            }
        }

        $payload = json_decode($rawBody, true) ?? [];
        $eventType = (string) ($payload['event_type'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        if ($eventType !== 'transaction.completed') {
            // Not handled yet (transaction.payment_failed, subscription.*,
            // adjustment.* for refunds — see class docblock). Acknowledge so
            // Paddle doesn't retry, but do nothing.
            return response('', 200);
        }

        $customData = (array) ($data['custom_data'] ?? []);
        $reference = (string) ($customData['eye_reference'] ?? '');
        $transactionId = (string) ($data['id'] ?? '');

        if ($reference === '') {
            Log::info('Paddle webhook: transaction.completed with no eye_reference', ['transaction_id' => $transactionId]);
            return response('', 200);
        }

        $payment = Payment::where('reference', $reference)->lockForUpdate()->first();
        if (!$payment) {
            Log::info('Paddle webhook: unknown reference', ['reference' => $reference]);
            return response('', 200);
        }

        // Idempotency: a webhook delivered twice for the same transaction must
        // not double-activate.
        if ($payment->status !== 'pending') {
            return response('', 200);
        }

        $amountTotal = (string) ($data['details']['totals']['total'] ?? '');
        $currency = (string) ($data['currency_code'] ?? 'USD');

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'currency' => $currency ?: $payment->currency,
            'metadata' => array_merge((array) ($payment->metadata ?? []), [
                'paddle_transaction_id' => $transactionId,
                'paddle_amount_total_minor' => $amountTotal,
            ]),
        ]);

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

        $payment->update(['subscription_id' => $newSubscription->id]);

        foreach ($oldActive as $sub) {
            if ($sub->id !== $newSubscription->id) {
                $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
        }

        return response('', 200);
    }

    /**
     * Verify Paddle's webhook signature.
     * Header shape: "ts=1671552777;h1=<hex hmac-sha256 of \"{ts}:{rawBody}\">"
     * https://developer.paddle.com/webhooks/signature-verification
     */
    private function verifySignature(string $header, string $rawBody, string $secret): bool
    {
        $parts = [];
        foreach (explode(';', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$k] = $v;
        }

        $ts = $parts['ts'] ?? '';
        $h1 = $parts['h1'] ?? '';
        if ($ts === '' || $h1 === '') {
            return false;
        }

        $computed = hash_hmac('sha256', "{$ts}:{$rawBody}", $secret);

        return hash_equals($computed, $h1);
    }
}
