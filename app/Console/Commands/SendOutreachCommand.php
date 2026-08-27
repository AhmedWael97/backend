<?php

namespace App\Console\Commands;

use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\OutreachEmail;
use App\Services\OutreachRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Send the pending outreach drafts, oldest first.
 *
 * This is the one command in the pipeline that talks to strangers, so it
 * refuses to run unless three things are true:
 *
 *  1. outreach.auto_send is on — off by default, because letting a scheduler
 *     mail people without anyone reading the message is a different risk class
 *     from letting it draft.
 *  2. outreach.postal_address is set — CAN-SPAM requires a physical address in
 *     every commercial email, and a missing one is a per-message violation.
 *  3. The warm-up ceiling allows it — a domain with no cold-sending history
 *     that jumps to 25/day gets filtered, wasting the first batches.
 *
 * Suppression is re-checked per message at send time, not just at draft time,
 * so someone who unsubscribed after their draft was written is never mailed.
 */
class SendOutreachCommand extends Command
{
    protected $signature = 'eye:send-outreach
        {--limit= : Cap for this run (default: today\'s warm-up ceiling)}
        {--user= : Lead owner (default config outreach.user_id)}
        {--force : Ignore the auto_send switch (for a supervised manual run)}
        {--dry-run : List what would be sent without sending}';

    protected $description = 'Send pending outreach drafts, subject to the auto-send switch, warm-up ramp and suppression list.';

    /** Date of the first send, for the warm-up ramp. */
    private const WARMUP_START = 'outreach:warmup_start';

    public function handle(): int
    {
        $userId = (int) ($this->option('user') ?: config('outreach.user_id', 1));
        $dryRun = (bool) $this->option('dry-run');

        if (!config('outreach.auto_send') && !$this->option('force') && !$dryRun) {
            $this->warn('Auto-send is off. Set OUTREACH_AUTO_SEND=true, or pass --force for a supervised run.');

            return self::SUCCESS;
        }

        $address = trim((string) config('outreach.postal_address'));
        if ($address === '' && !$dryRun) {
            $this->error('OUTREACH_POSTAL_ADDRESS is not set.');
            $this->line('CAN-SPAM requires a valid physical postal address in every commercial email.');

            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?: $this->ceilingForToday());
        if ($limit < 1) {
            $this->warn('Warm-up ceiling is 0 for today — nothing sent.');

            return self::SUCCESS;
        }

        $drafts = OutreachEmail::where('user_id', $userId)
            ->where('status', 'draft')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($drafts->isEmpty()) {
            $this->warn('No pending drafts. Run eye:outreach-daily first.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($drafts as $draft) {
            $to = (string) $draft->to_email;

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $draft->update(['status' => 'skipped']);
                $skipped++;
                continue;
            }

            // Re-checked here, not just at draft time: an opt-out that arrived
            // between drafting and sending must still be honoured.
            if (EmailSuppression::where('email', $to)->exists()) {
                $draft->update(['status' => 'skipped']);
                $this->line("  <fg=yellow>suppressed</> {$to}");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  <fg=gray>would send</> {$to} — {$draft->subject}");
                $sent++;
                continue;
            }

            $token = $draft->unsubscribe_token ?: Str::random(40);
            $html = OutreachRenderer::html((string) $draft->body, url("/api/v1/outreach/unsubscribe/{$token}"), $draft->meta);

            try {
                Mail::html($html, function ($m) use ($to, $draft) {
                    $m->to($to)->subject($draft->subject);

                    if ($from = config('outreach.from_address')) {
                        $m->from($from, (string) config('outreach.from_name', 'EYE Analytics'));
                    }
                    if ($replyTo = config('outreach.reply_to')) {
                        $m->replyTo($replyTo);
                    }
                });
                $draft->update(['status' => 'sent', 'unsubscribe_token' => $token, 'sent_at' => now()]);
                Lead::where('id', $draft->lead_id)->update(['status' => 'contacted', 'last_contacted_at' => now()]);
                $this->line("  <fg=green>sent</> {$to}");
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $draft->update(['status' => 'failed']);
                $this->line("  <fg=red>failed</> {$to} — {$e->getMessage()}");
                $failed++;
            }
        }

        if (!$dryRun && $sent > 0) {
            Cache::add(self::WARMUP_START, now()->toDateString(), now()->addYear());
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run — {$sent} would send, {$skipped} skipped (ceiling {$limit})."
            : "Sent {$sent}, failed {$failed}, skipped {$skipped} (ceiling {$limit}).");

        return self::SUCCESS;
    }

    /**
     * How many messages today's ramp allows. Counted from the first ever send,
     * so the ceiling reflects the domain's actual sending history rather than
     * the calendar.
     */
    private function ceilingForToday(): int
    {
        $target = (int) config('outreach.daily_target', 25);
        if (!config('outreach.warmup', true)) {
            return $target;
        }

        $start = Cache::get(self::WARMUP_START);
        if (!$start) {
            $schedule = (array) config('outreach.warmup_schedule', []);

            return (int) (reset($schedule) ?: $target);
        }

        $day = Carbon::parse($start)->diffInDays(now()) + 1;
        $ceiling = 0;
        foreach ((array) config('outreach.warmup_schedule', []) as $fromDay => $max) {
            if ($day >= (int) $fromDay) {
                $ceiling = (int) $max;
            }
        }

        return min($target, $ceiling ?: $target);
    }
}
