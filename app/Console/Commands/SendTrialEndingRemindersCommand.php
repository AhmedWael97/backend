<?php

namespace App\Console\Commands;

use App\Mail\TrialEndingMail;
use App\Models\EmailSuppression;
use App\Models\Subscription;
use App\Services\ClickHouseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * "Your trial ends soon" nudge — the missing third touch of the onboarding
 * drip (day 0 welcome -> day 2-3 no-domain nudge -> ~5 days before trial end).
 * Free-plan trials only; paid subscriptions have no current_period_end to
 * match, or aren't on the free plan.
 */
class SendTrialEndingRemindersCommand extends Command
{
    protected $signature = 'eye:send-trial-ending-reminders';
    protected $description = 'Email users whose free trial ends in ~5 days.';

    public function handle(ClickHouseService $clickhouse): int
    {
        $subs = Subscription::query()
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('slug', 'free'))
            ->whereBetween('current_period_end', [now()->addDays(4), now()->addDays(6)])
            ->whereHas('user', function ($q) {
                $q->whereNull('trial_ending_reminder_sent_at')
                    ->where('status', 'active')
                    ->whereNotIn('email', EmailSuppression::pluck('email'));
            })
            ->with('user')
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($subs as $sub) {
            $user = $sub->user;
            if (!$user) {
                continue;
            }

            $daysLeft = max(1, now()->diffInDays($sub->current_period_end, false));

            $domainIds = $user->domains()->pluck('id')->implode(', ');
            $visitors = 0;
            if ($domainIds !== '') {
                try {
                    $visitors = (int) ($clickhouse->select(
                        "SELECT uniq(visitor_id) AS c FROM events WHERE domain_id IN ({$domainIds})"
                    )[0]['c'] ?? 0);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            try {
                Mail::to($user->email)->queue(new TrialEndingMail($user, [
                    'days_left' => $daysLeft,
                    'visitors' => $visitors,
                ]));
                $user->update(['trial_ending_reminder_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: " . $e->getMessage());
            }
        }

        $this->line("Sent {$sent} trial-ending reminder(s).");

        return self::SUCCESS;
    }
}
