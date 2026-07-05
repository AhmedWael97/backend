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
            ->with('domains:id,user_id,domain')
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
            $domain = optional($user->domains->first())->domain;
            try {
                Mail::to($user->email)->queue(new BrandedEmail(
                    'No data from your site yet? Let\'s fix that',
                    [
                        'preheader' => 'Your tracking snippet may not be installed — quick check inside.',
                        'heading' => "Hi {$name}, we haven't seen any visitors yet",
                        'lines' => [
                            "You added " . ($domain ? "<strong>{$domain}</strong>" : 'your website') . " to EYE — nice! But we haven't received a single visit from it yet, which usually means the <strong>tracking snippet isn't installed</strong> (or not on every page).",
                            "Two quick things to check: the snippet is pasted just before <code>&lt;/head&gt;</code>, and it's on your live site (not only a draft/preview).",
                            "Open your dashboard and hit <strong>Verify installation</strong> — it tells you instantly whether EYE can see your site.",
                        ],
                        'ctaText' => 'Verify my installation',
                        'ctaUrl' => "{$appUrl}/en/settings/domains",
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
