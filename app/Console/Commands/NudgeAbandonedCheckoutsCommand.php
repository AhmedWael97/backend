<?php

namespace App\Console\Commands;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Nudges users who opened a Paymob checkout iframe but never completed
 * payment (Payment stays `pending` forever — the webhook only ever fires on
 * success/failure). Each Payment is emailed at most once, guarded by
 * payments.abandoned_nudge_sent_at.
 */
class NudgeAbandonedCheckoutsCommand extends Command
{
    protected $signature = 'eye:nudge-abandoned-checkouts';

    protected $description = 'Email users who started a Paymob checkout but never completed it.';

    public function handle(): int
    {
        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        // 3–48h old: long enough that the payment truly stalled (not just a
        // slow bank redirect), recent enough the plan choice is still relevant.
        $payments = Payment::query()
            ->where('status', 'pending')
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'paymob'))
            ->whereNull('abandoned_nudge_sent_at')
            ->whereBetween('created_at', [now()->subHours(48), now()->subHours(3)])
            ->with(['user', 'plan'])
            ->limit(200)
            ->get();

        $suppressed = EmailSuppression::pluck('email')->all();
        $sent = 0;

        foreach ($payments as $payment) {
            $user = $payment->user;
            if (!$user || in_array($user->email, $suppressed, true)) {
                $payment->abandoned_nudge_sent_at = now();
                $payment->save();
                continue;
            }

            $planName = $payment->plan?->name ?? 'your plan';
            $link = "{$appUrl}/en/settings/billing?resume=1";
            $name = $user->name ?: 'there';

            try {
                Mail::to($user->email)->queue(new BrandedEmail(
                    "Finish upgrading to {$planName} on EYE",
                    [
                        'preheader' => 'Your checkout was never completed — pick up right where you left off.',
                        'heading' => "Hi {$name}, your upgrade is still waiting",
                        'lines' => [
                            "You started upgrading to <strong>{$planName}</strong> but the payment never went through — no charge was made.",
                            'If the checkout window closed, timed out, or you just got busy, you can pick up right where you left off in under a minute.',
                        ],
                        'ctaText' => 'Finish upgrading',
                        'ctaUrl' => $link,
                        'replyNote' => "Had trouble paying, or Paymob didn't work for you? <strong>Just reply to this email</strong> — we can also activate your plan manually via a support ticket.",
                        'unsubUrl' => EmailController::unsubscribeUrl($user->email),
                    ]
                ));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }

            // Mark regardless so we never nag twice, even if delivery hiccups.
            $payment->abandoned_nudge_sent_at = now();
            $payment->save();
        }

        $this->info("Abandoned-checkout nudges sent: {$sent}");

        return self::SUCCESS;
    }
}
