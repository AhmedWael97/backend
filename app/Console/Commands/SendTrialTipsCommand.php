<?php

namespace App\Console\Commands;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Mid-trial feature-discovery nudge — the missing middle touch of the
 * onboarding drip: day 0 welcome -> day 2-3 no-domain nudge (SendOnboarding-
 * RemindersCommand) -> day ~10 feature tips (this) -> day ~25 trial-ending
 * (SendTrialEndingRemindersCommand). Only for free-trial users who already
 * connected a domain (no-domain users get the other nudge instead), so the
 * tips are relevant to someone actually seeing their own data.
 */
class SendTrialTipsCommand extends Command
{
    protected $signature = 'eye:send-trial-tips';

    protected $description = 'Email free-trial users (day ~10) about heatmaps, replay, and AI reports.';

    public function handle(): int
    {
        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $suppressed = EmailSuppression::pluck('email')->all();

        $subs = Subscription::query()
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('slug', 'free'))
            ->whereBetween('current_period_start', [now()->subDays(11), now()->subDays(9)])
            ->whereHas('user', function ($q) {
                $q->whereNull('trial_tips_sent_at')->where('status', 'active');
            })
            ->whereHas('user.domains')
            ->with('user')
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($subs as $sub) {
            $user = $sub->user;
            if (!$user || in_array($user->email, $suppressed, true)) {
                $sub->user?->update(['trial_tips_sent_at' => now()]);
                continue;
            }

            $name = $user->name ?: 'there';
            try {
                Mail::to($user->email)->queue(new BrandedEmail(
                    "3 things most people miss in the first week on EYE",
                    [
                        'preheader' => 'Heatmaps, session replay, and AI reports — a few clicks from where you already are.',
                        'heading' => "Hi {$name}, here's what's easy to miss",
                        'lines' => [
                            "<strong>Heatmaps</strong> show exactly where visitors click on each page — useful for spotting a CTA nobody notices.",
                            "<strong>Session replay</strong> lets you watch real visitor sessions, rage clicks and all — often faster than guessing why a page underperforms.",
                            "<strong>AI reports</strong> read your data and write a plain-English summary of what changed and why, so you don't have to dig through charts.",
                        ],
                        'ctaText' => 'Open my dashboard',
                        'ctaUrl' => "{$appUrl}/en/dashboard",
                        'replyNote' => "Not sure where to start? <strong>Just reply to this email</strong> — happy to point you at the right page.",
                        'unsubUrl' => EmailController::unsubscribeUrl($user->email),
                    ]
                ));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }

            $user->update(['trial_tips_sent_at' => now()]);
        }

        $this->info("Trial-tips emails sent: {$sent}");

        return self::SUCCESS;
    }
}
