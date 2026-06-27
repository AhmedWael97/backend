<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * "You're one step away" nudge: emails users who signed up but never added a
 * domain, recovering the most common activation drop-off. Each user is emailed
 * at most once (guarded by users.onboarding_reminder_sent_at).
 */
class SendOnboardingRemindersCommand extends Command
{
    protected $signature = 'eye:send-onboarding-reminders';

    protected $description = "Email users who signed up but haven't added a domain yet.";

    public function handle(): int
    {
        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        // Nudge accounts that are 2–72h old: old enough to have lapsed, recent
        // enough to still care. Skip org members (they use assigned domains).
        $users = User::query()
            ->where('role', 'user')
            ->whereNull('onboarding_reminder_sent_at')
            ->whereBetween('created_at', [now()->subHours(72), now()->subHours(2)])
            ->whereDoesntHave('domains')
            ->whereDoesntHave('organizationMemberships')
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            $link = "{$appUrl}/en/settings/domains?welcome=1";
            try {
                Mail::raw(
                    "Hi {$user->name},\n\n"
                    . "You're one step away from seeing who visits your website.\n\n"
                    . "Add your site and paste the tracking snippet (about 2 minutes) — then EYE starts "
                    . "showing you visitors, heatmaps, session replays and AI insights:\n{$link}\n\n"
                    . "Stuck? Just reply to this email and we'll help you set it up.\n\n— The EYE team",
                    fn ($m) => $m->to($user->email)->subject("You're one step away on EYE 👀")
                );
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
            // Mark regardless so we never nag twice, even if delivery hiccups.
            $user->onboarding_reminder_sent_at = now();
            $user->save();
        }

        $this->info("Onboarding reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
