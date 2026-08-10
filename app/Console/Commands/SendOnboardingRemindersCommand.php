<?php

namespace App\Console\Commands;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * 3-part activation drip for users who signed up but never added a domain —
 * Day 1 / Day 3 / Day 5, each sent at most once (guarded by
 * onboarding_reminder_stage). Stage 1 also sweeps up old backlog signups
 * (no upper age limit beyond 180d — a dormant account from months ago still
 * gets a first nudge). Stages 2/3 are gated off the PREVIOUS stage's send
 * time, not original signup — so a backlog user caught up at stage 1 today
 * gets stage 2 two days from today, not immediately in the same run.
 * Stops the moment a domain or org membership shows up.
 */
class SendOnboardingRemindersCommand extends Command
{
    protected $signature = 'eye:send-onboarding-reminders';

    protected $description = "Day 1/3/5 activation drip for users who signed up but haven't added a domain yet.";

    public function handle(): int
    {
        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        // UTM-tagged so returns from this drip show up in our own (dogfooded)
        // Campaigns dashboard, split by stage — real return-rate signal per email.

        $stages = [
            1 => [
                'minHours' => 24,
                'subject' => "Welcome to EYE — install your tag in 2 minutes 👀",
                'preheader' => 'One line of code, then your visitors start showing up.',
                'lines' => [
                    "Thanks for creating an EYE account — you're one short step from seeing real visitors on your site.",
                    "Add your domain and paste one tracking tag (guides included for WordPress, Shopify, and plain HTML) and data starts flowing within seconds.",
                ],
                'ctaText' => 'Install my tracking tag',
            ],
            2 => [
                'minHours' => 72,
                'subject' => "Did you know EYE reads your data for you?",
                'preheader' => "The AI daily digest tells you what to fix — not just what happened.",
                'lines' => [
                    "Most analytics tools hand you charts and leave you to figure out what they mean. EYE's <strong>AI daily digest</strong> reads your traffic every morning and writes it in plain English — where visitors drop off, why, and exactly what to do next.",
                    "You just need a website connected first. Still takes about 2 minutes.",
                ],
                'ctaText' => 'Connect my website',
            ],
            3 => [
                'minHours' => 120,
                'subject' => "Need a hand getting set up?",
                'preheader' => "Reply to this email — a real person will help.",
                'lines' => [
                    "Noticed you haven't connected a site yet. If something's unclear or you got stuck, <strong>just reply to this email</strong> and I'll personally help you get it running — or use the live chat on eye-analysis.online, it's answered instantly.",
                    "If EYE isn't the right fit right now, no worries — you can always come back later.",
                ],
                'ctaText' => 'Finish setup',
            ],
        ];

        $sent = 0;

        foreach ($stages as $stage => $cfg) {
            $query = User::query()
                ->where('role', 'user')
                ->where('onboarding_reminder_stage', $stage - 1)
                ->whereDoesntHave('domains')
                ->whereDoesntHave('organizationMemberships')
                ->whereNotIn('email', EmailSuppression::pluck('email'));

            if ($stage === 1) {
                // First touch: gated off signup age. No lower bound beyond
                // 24h, and a generous 180d ceiling just to skip truly dead
                // accounts — this is what catches the old-signup backlog.
                $query->where('created_at', '<=', now()->subHours($cfg['minHours']))
                    ->where('created_at', '>=', now()->subDays(180));
            } else {
                // Later touches: gated off the PREVIOUS email's send time, so
                // spacing is real days-apart even for backlog users who just
                // got stage 1 today.
                $query->where('onboarding_reminder_sent_at', '<=', now()->subHours($cfg['minHours'] - $stages[$stage - 1]['minHours']));
            }

            $users = $query->limit(200)->get();
            $connectLink = "{$appUrl}/en/connect?utm_source=email&utm_medium=lifecycle&utm_campaign=onboarding_drip&utm_content=day{$cfg['minHours']}";

            foreach ($users as $user) {
                $name = $user->name ?: 'there';
                try {
                    Mail::to($user->email)->queue(new BrandedEmail(
                        $cfg['subject'],
                        [
                            'preheader' => $cfg['preheader'],
                            'heading' => "Hi {$name},",
                            'lines' => $cfg['lines'],
                            'ctaText' => $cfg['ctaText'],
                            'ctaUrl' => $connectLink,
                            'replyNote' => $stage === 3
                                ? null
                                : "Ran into a snag? <strong>Just reply to this email</strong> — we read every message and will help you get set up.",
                            'unsubUrl' => EmailController::unsubscribeUrl($user->email),
                        ]
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                }
                // Mark regardless of delivery success so a mail hiccup never
                // stalls the drip or causes a duplicate send next run.
                $user->onboarding_reminder_stage = $stage;
                $user->onboarding_reminder_sent_at = now();
                $user->save();
            }
        }

        $this->info("Onboarding drip emails sent: {$sent}");

        return self::SUCCESS;
    }
}
