<?php

namespace App\Console\Commands;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Domains that never received a single event get cleaned up automatically —
 * day 6: warn the owner; day 7+: remove it. Keeps fake/junk/typo'd domain
 * entries from piling up forever. A domain that has ANY real event is never
 * touched, no matter how old.
 */
class CleanupStaleDomainsCommand extends Command
{
    protected $signature = 'eye:cleanup-stale-domains';

    protected $description = 'Warn (day 6) then remove (day 7+) domains that never received any tracking data.';

    public function handle(ClickHouseService $ch): int
    {
        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $warned = 0;
        $removed = 0;

        // ── Day 6: warn ──────────────────────────────────────────────────
        $warnCandidates = Domain::where('is_demo', false)
            ->where('active', true)
            ->whereNull('stale_warning_sent_at')
            ->whereBetween('created_at', [now()->subDays(7), now()->subDays(6)])
            ->with('user')
            ->get();

        foreach ($warnCandidates as $domain) {
            if ($this->hasEvents($ch, $domain->id)) {
                // Has real data — mark processed so we never re-check it, but no email.
                $domain->update(['stale_warning_sent_at' => now()]);
                continue;
            }

            if ($domain->user) {
                try {
                    Mail::to($domain->user->email)->queue(new BrandedEmail(
                        "Removing {$domain->domain} tomorrow — no data received",
                        [
                            'preheader' => "It's been 6 days with zero visitors detected. Install the tag to keep it.",
                            'heading' => "Hi {$domain->user->name},",
                            'lines' => [
                                "You added <strong>{$domain->domain}</strong> to EYE 6 days ago, but we still haven't received a single visitor from it — the tracking tag likely isn't installed.",
                                "To keep your account tidy, we'll automatically remove this domain <strong>tomorrow</strong> if it still has no data. This is easy to undo: just add it again any time and pick up where you left off.",
                            ],
                            'ctaText' => 'Install the tag now',
                            'ctaUrl' => "{$appUrl}/en/install/{$domain->script_token}",
                            'replyNote' => "Think this is a mistake? <strong>Reply to this email</strong> and we'll help.",
                            'unsubUrl' => EmailController::unsubscribeUrl($domain->user->email),
                        ]
                    ));
                    $warned++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $domain->update(['stale_warning_sent_at' => now()]);
        }

        // ── Day 7+: remove ───────────────────────────────────────────────
        $removeCandidates = Domain::where('is_demo', false)
            ->where('active', true)
            ->where('created_at', '<=', now()->subDays(7))
            ->get();

        foreach ($removeCandidates as $domain) {
            if ($this->hasEvents($ch, $domain->id)) {
                continue;
            }
            $domain->delete();
            $removed++;
        }

        $this->info("Stale domains — warned: {$warned}, removed: {$removed}");

        return self::SUCCESS;
    }

    private function hasEvents(ClickHouseService $ch, int $domainId): bool
    {
        try {
            $rows = $ch->select("SELECT count() AS c FROM events WHERE domain_id = {$domainId} LIMIT 1");
            return (int) ($rows[0]['c'] ?? 0) > 0;
        } catch (\Throwable $e) {
            report($e);
            // ClickHouse hiccup must never cause a false "no data" deletion.
            return true;
        }
    }
}
