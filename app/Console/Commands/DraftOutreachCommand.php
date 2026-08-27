<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tools\SeoCheckerController;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\OutreachEmail;
use App\Models\Plan;
use App\Models\User;
use App\Services\AiTextService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Audit each new lead's site, then draft an outreach email built ONLY from what
 * the audit actually found.
 *
 * The July run sent 97 generic "run every client site from one dashboard"
 * emails and converted nobody. The difference here is that the message opens
 * with real defects on the recipient's own site — the same audit the landing
 * page now runs — which gives them a reason to read on, and something they can
 * forward to a client.
 *
 * The AI never invents a finding. It receives the deterministic issue list from
 * SeoCheckerController and is instructed to describe only those; if it is
 * unconfigured or fails, a plain template lists the same issues verbatim. One
 * hallucinated defect would cost more trust than the whole batch earns.
 *
 * Nothing is sent. Drafts land as outreach_emails rows with status `draft` for
 * a human to review and send.
 *
 * Usage:
 *   php artisan eye:draft-outreach --user=1 --limit=25
 *   php artisan eye:draft-outreach --user=1 --limit=5 --dry-run
 */
class DraftOutreachCommand extends Command
{
    protected $signature = 'eye:draft-outreach
        {--user= : User id whose leads to draft for (defaults to the first superadmin)}
        {--limit=25 : Maximum drafts to create}
        {--min-issues=2 : Skip sites with fewer real issues than this — nothing useful to say}
        {--dry-run : Print the drafts without saving them}';

    protected $description = 'Audit new leads and draft a findings-based outreach email for each (never sends).';

    public function handle(SeoCheckerController $auditor, AiTextService $ai): int
    {
        $user = $this->option('user')
            ? User::find((int) $this->option('user'))
            : User::where('role', 'superadmin')->orderBy('id')->first();

        if (!$user) {
            $this->error('No user found — pass --user=<id>.');

            return self::FAILURE;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $minIssues = max(1, (int) $this->option('min-issues'));
        $dryRun = (bool) $this->option('dry-run');

        $leads = Lead::where('user_id', $user->id)
            ->where('status', 'new')
            ->whereNotNull('email')
            ->whereNotNull('website')
            // Skip anything already drafted so re-runs top up rather than duplicate.
            ->whereNotIn('id', OutreachEmail::where('user_id', $user->id)->whereNotNull('lead_id')->pluck('lead_id'))
            ->orderByDesc('score')
            ->limit($limit * 2)
            ->get();

        if ($leads->isEmpty()) {
            $this->warn('No un-drafted leads with an email address. Run eye:source-leads first.');

            return self::SUCCESS;
        }

        $drafted = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            if ($drafted >= $limit) {
                break;
            }

            if (EmailSuppression::where('email', $lead->email)->exists()) {
                $this->line("  <fg=yellow>suppressed</> {$lead->email}");
                $skipped++;
                continue;
            }

            $audit = $auditor->auditUrl($this->normalizeUrl((string) $lead->website));
            if ($audit === null) {
                $this->line("  <fg=red>unreachable</> {$lead->website}");
                $skipped++;
                continue;
            }

            // A clean site gives us nothing honest to lead with. Better to say
            // nothing than to manufacture a problem.
            if (count($audit['issues']) < $minIssues) {
                $this->line("  <fg=yellow>clean</> {$lead->website} (score {$audit['score']}) — nothing to report");
                $skipped++;
                continue;
            }

            $issues = array_slice($audit['issues'], 0, 4);
            [$subject, $body] = $this->compose($ai, $lead, $audit, $issues);
            $host = (string) parse_url($this->normalizeUrl((string) $lead->website), PHP_URL_HOST);

            // The same content the body carries, kept structured so the renderer
            // can lay it out as a designed email rather than nl2br over prose.
            $meta = [
                'host' => $host,
                'score' => (int) $audit['score'],
                'issue_count' => count($audit['issues']),
                'issues' => array_map(fn (array $i) => [
                    'label' => (string) ($i['label'] ?? ''),
                    'message' => (string) ($i['message'] ?? ''),
                    'suggestion' => (string) ($i['suggestion'] ?? ''),
                    'severity' => (string) ($i['severity'] ?? ''),
                ], $issues),
                'scan_url' => $this->scanUrl(),
                'pricing' => $this->pricingLine(),
            ];

            $this->line(sprintf(
                '  <fg=green>ok</> %-34s score %-3d %d issues',
                mb_substr((string) $lead->website, 0, 34),
                $audit['score'],
                count($audit['issues'])
            ));

            if ($dryRun) {
                $this->line("     {$subject}");
                $drafted++;
                continue;
            }

            OutreachEmail::create([
                'user_id' => $user->id,
                'lead_id' => $lead->id,
                'to_email' => $lead->email,
                'subject' => mb_substr($subject, 0, 255),
                'body' => $body,
                'meta' => $meta,
                'status' => 'draft',
                'unsubscribe_token' => Str::random(40),
            ]);

            $lead->update([
                'score' => min(100, (int) $lead->score + 20),
                'notes' => mb_substr(
                    trim((string) $lead->notes . " · audit score {$audit['score']}, " . count($audit['issues']) . ' issues'),
                    0,
                    1000
                ),
            ]);

            $drafted++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run — {$drafted} drafts would be created, {$skipped} skipped."
            : "Created {$drafted} drafts, skipped {$skipped}. Review and send them from the Leads page.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{0: string, 1: string}
     */
    private function compose(AiTextService $ai, Lead $lead, array $audit, array $issues): array
    {
        $company = $lead->company ?: 'your team';
        $host = (string) parse_url($this->normalizeUrl((string) $lead->website), PHP_URL_HOST);
        $issueCount = count($audit['issues']);

        $findings = '';
        foreach ($issues as $issue) {
            $findings .= '- ' . ($issue['label'] ?? '') . ': ' . ($issue['message'] ?? '')
                . ' — fix: ' . ($issue['suggestion'] ?? 'see report') . "\n";
        }

        $scanUrl = $this->scanUrl();
        $pricing = $this->pricingLine();

        // AiTextService takes a single prompt and fails over between providers,
        // so the instructions and the data go in together.
        $prompt = 'You write short B2B outreach emails (under 160 words), plain text, no hype, no emoji.' . "\n"
            . 'CRITICAL RULE: you may ONLY reference the findings given below. Never invent, infer, '
            . 'embellish or add any issue that is not in that list. If the list is short, write a shorter '
            . 'email. Do not promise rankings or revenue numbers. Copy the link and the pricing line '
            . 'EXACTLY as given — never alter a price or a URL.' . "\n"
            . 'Return ONLY JSON: {"subject": "...", "body": "..."} using \n for line breaks.' . "\n\n"
            . "Write to {$company} ({$host}).\n\n"
            . "We ran an automated technical check on their homepage. Score {$audit['score']}/100, "
            . "{$issueCount} issues found. The verified findings are:\n{$findings}\n"
            . "Include, verbatim, this link on its own line: {$scanUrl}\n"
            . "Include, verbatim, this pricing sentence: {$pricing}\n\n"
            . 'Open by naming two or three of these findings specifically. Invite them to run the same check '
            . 'on any client site using the link (free, no account). Then the pricing sentence. '
            . 'End with one clear ask: reply if they want the full report. '
            . 'Sign off as the EYE Analytics team.';

        try {
            $result = $ai->generate($prompt, 800);
            $decoded = $this->decodeJson((string) ($result['text'] ?? ''));
            $subject = trim((string) ($decoded['subject'] ?? ''));
            $body = trim((string) ($decoded['body'] ?? ''));
            // A model that dropped the link or mangled the price is worse than
            // the template — fall through rather than ship a broken CTA.
            if ($subject !== '' && $body !== '' && str_contains($body, $scanUrl)) {
                return [$subject, $body];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Deterministic fallback — the same verified findings, no AI involved.
        return [
            "{$host}: {$issueCount} technical issues we found",
            "Hi,\n\nWe ran an automated technical check on {$host} and it scored {$audit['score']}/100. "
                . "What came back:\n\n{$findings}\n"
                . "Run the same check on any client site — free, no account needed:\n{$scanUrl}\n\n"
                . 'We build EYE Analytics — privacy-first analytics with heatmaps, session replay and '
                . "campaign ROAS, for watching every client site from one dashboard.\n{$pricing}\n\n"
                . "Reply if you would like the full report for {$host}.\n\n— The EYE Analytics team",
        ];
    }

    /**
     * The landing page's own audit box, UTM-tagged so replies from this campaign
     * show up in our own Campaigns dashboard instead of as anonymous direct hits.
     */
    private function scanUrl(): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return "{$base}/en?utm_source=outreach&utm_medium=email&utm_campaign=agency_audit";
    }

    /**
     * Priced from the live plans table rather than hardcoded, so a price change
     * in the admin panel cannot leave the outreach quoting a stale number.
     */
    private function pricingLine(): string
    {
        $plan = Plan::where('slug', 'agency')->first() ?: Plan::where('slug', 'pro')->first();
        if (!$plan) {
            return 'Plans start at $20/month, with a 30-day free trial.';
        }

        $price = rtrim(rtrim(number_format((float) $plan->price_monthly, 2), '0'), '.');
        $sites = (int) $plan->getLimit('domains', 5);
        $seats = (int) $plan->getLimit('team_members', 10);

        return "The {$plan->name} plan is \${$price}/month for {$sites} client sites and {$seats} team seats, "
            . 'with a 30-day free trial.';
    }

    /**
     * Models like to wrap JSON in prose or a ``` fence — dig the object out
     * rather than losing a whole draft to a stray line of preamble.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
        $text = trim((string) preg_replace('/```(?:json)?/i', '', $text));
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        return (array) (json_decode(substr($text, $start, $end - $start + 1), true) ?? []);
    }

    private function normalizeUrl(string $website): string
    {
        $website = trim($website);

        return preg_match('#^https?://#i', $website) ? $website : "https://{$website}";
    }
}
