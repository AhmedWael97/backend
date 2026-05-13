<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['user', 'plan', 'paymentMethod']);

        if ($status = $request->query('status'))
            $query->where('status', $status);
        if ($method = $request->query('method'))
            $query->whereHas('paymentMethod', fn($q) => $q->where('type', $method));
        if ($from = $request->query('from'))
            $query->where('created_at', '>=', $from);
        if ($to = $request->query('to'))
            $query->where('created_at', '<=', $to);

        return $this->paginated($query->latest()->paginate(50));
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(Payment::with(['user', 'plan'])->findOrFail($id));
    }

    /**
     * POST /api/admin/payments/{id}/approve
     *
     * Marks a pending payment as paid. For AI-token purchases this is the
     * point at which tokens are actually credited — they are NOT credited at
     * purchaseTokens time. For subscription bank transfers, this activates the
     * subscription.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        if ($payment->status !== 'pending') {
            return $this->error('Only pending payments can be approved.', 422);
        }

        \DB::transaction(function () use ($payment, $request) {
            $payment->update(['status' => 'paid', 'paid_at' => now()]);

            $metadata = (array) ($payment->metadata ?? []);

            // AI-token purchase: credit the tokens now.
            if (($metadata['type'] ?? null) === 'ai_tokens') {
                $tokens = (int) ($metadata['tokens'] ?? 0);
                if ($tokens > 0 && $payment->user) {
                    $payment->user->increment('ai_tokens', $tokens);
                }
            }

            // Subscription payment: activate the linked subscription if any.
            if ($payment->subscription_id) {
                \App\Models\Subscription::where('id', $payment->subscription_id)
                    ->update([
                        'status' => 'active',
                        'current_period_start' => now(),
                        'current_period_end' => now()->addMonth(),
                    ]);
            }

            AuditLog::create([
                'admin_id' => $request->user()->id,
                'action' => 'payment.approve',
                'target_type' => 'Payment',
                'target_id' => $payment->id,
                'before' => ['status' => 'pending'],
                'after' => ['status' => 'paid'],
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });

        return $this->success(['message' => 'Payment approved.']);
    }

    /**
     * POST /api/admin/payments/{id}/refund
     *
     * Reverses a paid payment. For AI-token purchases the previously-credited
     * tokens are subtracted (floor at zero). For subscriptions the linked
     * subscription is cancelled.
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        if ($payment->status !== 'paid') {
            return $this->error('Only paid payments can be refunded.', 422);
        }

        $before = ['status' => $payment->status];

        \DB::transaction(function () use ($payment) {
            $payment->update(['status' => 'refunded']);

            $metadata = (array) ($payment->metadata ?? []);

            // AI-token refund: subtract the tokens we credited at approval.
            if (($metadata['type'] ?? null) === 'ai_tokens') {
                $tokens = (int) ($metadata['tokens'] ?? 0);
                if ($tokens > 0 && $payment->user) {
                    $newBalance = max(0, ((int) $payment->user->ai_tokens) - $tokens);
                    $payment->user->update(['ai_tokens' => $newBalance]);
                }
            }

            // Subscription refund: cancel the linked subscription.
            if ($payment->subscription_id) {
                \App\Models\Subscription::where('id', $payment->subscription_id)
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
        });

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'payment.refund',
            'target_type' => 'Payment',
            'target_id' => $id,
            'before' => $before,
            'after' => ['status' => 'refunded'],
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return $this->success(['message' => 'Payment refunded.']);
    }
}
