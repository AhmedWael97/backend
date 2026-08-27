<?php

namespace App\Console\Commands;

use App\Models\OutreachEmail;
use App\Services\OutreachRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Send one existing draft to your own inbox to see exactly what a prospect
 * would receive — same renderer, same unsubscribe footer.
 *
 * The draft is left untouched: still `draft`, still un-sent, and the prospect
 * is never written to. The recipient is passed explicitly rather than taken
 * from the draft precisely so a preview can never leak to the real company.
 *
 * Usage:
 *   php artisan eye:preview-outreach 5 --to=me@example.com
 *   php artisan eye:preview-outreach --to=me@example.com   (uses the oldest draft)
 */
class PreviewOutreachCommand extends Command
{
    protected $signature = 'eye:preview-outreach
        {draft? : Draft id — defaults to the oldest pending draft}
        {--to= : Where to send the preview (required)}';

    protected $description = 'Send a copy of one outreach draft to your own inbox, without touching the draft.';

    public function handle(): int
    {
        $to = trim((string) $this->option('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to must be a valid email address.');

            return self::FAILURE;
        }

        $draft = $this->argument('draft')
            ? OutreachEmail::where('status', 'draft')->find((int) $this->argument('draft'))
            : OutreachEmail::where('status', 'draft')->orderBy('id')->first();

        if (!$draft) {
            $this->error('No matching draft found. Run eye:draft-outreach first.');

            return self::FAILURE;
        }

        // Show it on stdout too, so the template can be reviewed even if the
        // mail transport is misconfigured.
        $this->newLine();
        $this->line("<fg=gray>Draft #{$draft->id} — would go to {$draft->to_email}</>");
        $this->line("<fg=gray>Previewing to {$to}</>");
        $this->newLine();
        $this->line("<options=bold>Subject:</> {$draft->subject}");
        $this->newLine();
        $this->line((string) $draft->body);
        $this->newLine();

        $unsubUrl = url("/api/v1/outreach/unsubscribe/{$draft->unsubscribe_token}");
        $html = OutreachRenderer::html((string) $draft->body, $unsubUrl);

        try {
            Mail::html($html, function ($m) use ($to, $draft) {
                $m->to($to)->subject('[PREVIEW] ' . $draft->subject);
            });
        } catch (\Throwable $e) {
            $this->error('Send failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Preview sent to {$to}. Draft #{$draft->id} is unchanged and still un-sent.");

        return self::SUCCESS;
    }
}
