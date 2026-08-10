<?php

namespace App\Console\Commands;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * "No data yet?" check-up: emails users who added a domain but whose site has
 * never sent a single tracking event (snippet probably not installed). Sent once
 * per user (users.checkup_sent_at), only after the domain is 24h+ old.
 */
class SendDomainCheckupCommand extends Command
{
    protected $signature = 'eye:send-domain-checkup';

    protected $description = 'Email users who added a domain but received no tracking events.';

    public function handle(ClickHouseService $ch): int
    {
        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        $users = User::query()
            ->where('role', 'user')
            ->whereNull('checkup_sent_at')
            ->whereHas('domains', fn ($q) => $q->where('domains.created_at', '<', now()->subHours(24)))
            ->whereNotIn('email', EmailSuppression::pluck('email'))
            ->with('domains:id,user_id,domain,script_token')
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            $ids = $user->domains->pluck('id')->all();
            if (empty($ids)) {
                continue;
            }
            $inList = implode(',', array_map('intval', $ids));
            $rows = $ch->select("SELECT count() AS c FROM events WHERE domain_id IN ({$inList})");
            $events = (int) ($rows[0]['c'] ?? 0);

            if ($events > 0) {
                // Data is flowing — no check-up needed. Mark so we don't re-scan forever.
                $user->checkup_sent_at = now();
                $user->save();
                continue;
            }

            $name = $user->name ?: 'there';
            $firstDomain = $user->domains->first();
            $domain = optional($firstDomain)->domain;
            // No-login guide, so a stuck non-technical user (or the developer
            // they forward this to) doesn't have to sign in just to see steps.
            $ctaUrl = $firstDomain
                ? "{$appUrl}/en/install/{$firstDomain->script_token}"
                : "{$appUrl}/en/connect";
            try {
                Mail::to($user->email)->queue(new BrandedEmail(
                    'No data from your site yet? Let\'s fix that',
                    [
                        'preheader' => 'Your tracking snippet may not be installed — quick check inside.',
                        'heading' => "Hi {$name}, we haven't seen any visitors yet",
                        'lines' => [
                            "You added " . ($domain ? "<strong>{$domain}</strong>" : 'your website') . " to EYE — nice! But we haven't received a single visit from it yet, which usually means the <strong>tracking snippet isn't installed</strong> (or not on every page).",
                            "Tap the button below — no login needed. Pick your platform (WordPress, Shopify, Tag Manager, or plain HTML) and follow the exact steps, or forward the link to whoever manages your site.",
                        ],
                        'ctaText' => 'See install steps',
                        'ctaUrl' => $ctaUrl,
                        'replyNote' => "Not sure where to paste it, or on a platform we didn't cover? <strong>Reply to this email</strong> and we'll walk you through it.",
                        'unsubUrl' => EmailController::unsubscribeUrl($user->email),
                    ]
                ));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
            $user->checkup_sent_at = now();
            $user->save();
        }

        $this->info("Domain check-up emails sent: {$sent}");

        return self::SUCCESS;
    }
}
