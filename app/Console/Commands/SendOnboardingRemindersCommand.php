<?php

namespace App\Console\Commands;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
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
            ->whereNotIn('email', EmailSuppression::pluck('email'))
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            $link = "{$appUrl}/en/settings/domains?welcome=1";
            $name = $user->name ?: 'there';
            try {
                Mail::to($user->email)->queue(new BrandedEmail(
                    "You're one step away on EYE 👀",
                    [
                        'preheader' => 'Add your website (2 minutes) to start seeing your visitors.',
                        'heading' => "Hi {$name}, you're one step away",
                        'lines' => [
                            "You created an EYE account but haven't connected a website yet — so there's no data flowing in.",
                            "Adding your site takes about <strong>2 minutes</strong>: paste one line of code (we have guides for WordPress, Shopify, and plain HTML) and EYE starts showing your visitors, heatmaps, session replays, and AI insights.",
                        ],
                        'ctaText' => 'Add my website',
                        'ctaUrl' => $link,
                        'replyNote' => "Ran into a snag, or the setup wasn't clear? <strong>Just reply to this email</strong> — we read every message and will help you get set up.",
                        'unsubUrl' => EmailController::unsubscribeUrl($user->email),
                    ]
                ));
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
