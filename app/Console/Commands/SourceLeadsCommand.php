<?php

namespace App\Console\Commands;

use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\User;
use App\Services\ContactFinderService;
use App\Services\LeadSourceService;
use Illuminate\Console\Command;

/**
 * Fill the leads table from an open/licensed business directory, then look up
 * each company's own published contact address.
 *
 * Split from drafting on purpose: sourcing is cheap and repeatable, drafting
 * costs AI tokens and should only run on leads that survived review. Leads land
 * as status `new` with no email sent — `eye:draft-outreach` writes the message
 * and a human still has to send it.
 *
 * Usage:
 *   php artisan eye:source-leads --user=1 --area="Austin" --category=marketing --limit=25
 *   php artisan eye:source-leads --user=1 --provider=places --area="Toronto" --dry-run
 */
class SourceLeadsCommand extends Command
{
    protected $signature = 'eye:source-leads
        {--user= : User id the leads belong to (defaults to the first superadmin)}
        {--provider=osm : osm (free, OpenStreetMap) or places (Google Places, needs a key)}
        {--area= : City or region name, e.g. "Austin" or "Greater Manchester"}
        {--category=agency : agency, marketing, or web}
        {--limit=25 : How many leads to keep}
        {--no-email : Skip contact-page lookup, just record company + website}
        {--dry-run : Show what would be saved without writing anything}';

    protected $description = 'Find prospect companies from OpenStreetMap or Google Places and record them as leads.';

    public function handle(LeadSourceService $sources, ContactFinderService $contacts): int
    {
        $area = trim((string) $this->option('area'));
        if ($area === '') {
            $this->error('--area is required (e.g. --area="Austin").');

            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if (!$user) {
            $this->error('No user found — pass --user=<id>.');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');
        if (!$sources->available($provider)) {
            $this->error("Provider [{$provider}] is not configured (GOOGLE_PLACES_API_KEY missing?).");

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Searching {$provider} for {$this->option('category')} companies in \"{$area}\"…");
        // Over-fetch: a good share of results get dropped as duplicates or as
        // companies with no reachable contact page.
        $found = $sources->search($provider, $area, (string) $this->option('category'), $limit * 2);

        if ($found === []) {
            $this->warn('No companies returned. Try a different area name, or --provider=places for better coverage.');

            return self::SUCCESS;
        }

        $this->info(count($found) . ' companies returned. Resolving contacts…');

        $kept = 0;
        $skipped = 0;
        $rows = [];
        // Rows are written after the loop, so the DB check below cannot see
        // earlier hits from this same run — a company listed twice in the
        // directory (two offices, say) would otherwise be saved twice.
        $seenHosts = [];

        foreach ($found as $company) {
            if ($kept >= $limit) {
                break;
            }

            $website = $company['website'];
            $host = strtolower((string) preg_replace('/^www\./i', '', (string) parse_url(
                str_contains($website, '://') ? $website : "https://{$website}",
                PHP_URL_HOST
            )));
            if ($host === '') {
                $skipped++;
                continue;
            }

            // Same company from a second run, or a second office of one already
            // recorded — match on host so www/https variants collapse.
            if (isset($seenHosts[$host])
                || Lead::where('user_id', $user->id)->where('website', 'ILIKE', "%{$host}%")->exists()) {
                $skipped++;
                continue;
            }
            $seenHosts[$host] = true;

            $email = $company['email'];
            $sourceUrl = null;
            if ($email === null && !$this->option('no-email')) {
                $contact = $contacts->find($website);
                $email = $contact['email'];
                $sourceUrl = $contact['source_url'];
            }

            // Someone who already opted out must never re-enter the pipeline.
            if ($email !== null && EmailSuppression::where('email', $email)->exists()) {
                $this->line("  <fg=yellow>suppressed</> {$host} ({$email})");
                $skipped++;
                continue;
            }

            $notes = $company['notes'];
            if ($sourceUrl) {
                $notes .= " · contact found at {$sourceUrl}";
            }

            $rows[] = [
                'user_id' => $user->id,
                'company' => $company['company'],
                'website' => $website,
                'email' => $email,
                'source' => $provider === 'places' ? 'places' : 'osm',
                'status' => 'new',
                // A lead we can actually write to is worth more than one we cannot.
                'score' => $email !== null ? 60 : 20,
                'notes' => mb_substr($notes, 0, 1000),
            ];

            $this->line(sprintf(
                '  %s %-38s %s',
                $email ? '<fg=green>✓</>' : '<fg=yellow>?</>',
                mb_substr($host, 0, 38),
                $email ?: 'no public contact address found'
            ));
            $kept++;
        }

        if ($dryRun) {
            $this->newLine();
            $this->info("Dry run — {$kept} would be saved, {$skipped} skipped. Nothing written.");

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            Lead::create($row);
        }

        $withEmail = count(array_filter($rows, fn ($r) => $r['email'] !== null));
        $this->newLine();
        $this->info("Saved {$kept} leads ({$withEmail} with a contact address), skipped {$skipped}.");
        $this->line('Next: php artisan eye:draft-outreach --user=' . $user->id . ' --limit=' . $kept);

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $id = $this->option('user');

        return $id ? User::find((int) $id) : User::where('role', 'superadmin')->orderBy('id')->first();
    }
}
