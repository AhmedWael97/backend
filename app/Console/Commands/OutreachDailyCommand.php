<?php

namespace App\Console\Commands;

use App\Models\OutreachEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * One scheduled run of the top of the funnel: source companies, resolve their
 * contact addresses, audit their sites and leave drafts ready to review.
 *
 * Sourcing and drafting stay in their own commands (eye:source-leads /
 * eye:draft-outreach) so either can be run by hand against a chosen city. This
 * one just drives them to a daily target and rotates the search area, so the
 * same place is not mined dry night after night.
 *
 * Never sends. Sending is eye:send-outreach, behind its own switch.
 */
class OutreachDailyCommand extends Command
{
    protected $signature = 'eye:outreach-daily
        {--target= : Mailable drafts to aim for (default config outreach.daily_target)}
        {--user= : Lead owner (default config outreach.user_id)}
        {--dry-run : Show what would happen without writing}';

    protected $description = 'Daily pipeline: source US leads, audit their sites, and draft outreach for review.';

    /** Remembers where the rotation got to between runs. */
    private const AREA_CURSOR = 'outreach:area_cursor';

    public function handle(): int
    {
        $target = (int) ($this->option('target') ?: config('outreach.daily_target', 25));
        $userId = (int) ($this->option('user') ?: config('outreach.user_id', 1));
        $dryRun = (bool) $this->option('dry-run');

        $areas = (array) config('outreach.areas', []);
        if ($areas === []) {
            $this->error('No areas configured in config/outreach.php.');

            return self::FAILURE;
        }

        $before = $this->draftCount($userId);

        // Roughly half of sourced companies publish a contact address, and some
        // sites are unreachable or come back clean, so aim well above target.
        $needed = $target * 3;
        $cursor = (int) Cache::get(self::AREA_CURSOR, 0);
        $queries = (int) ceil($needed / 20);

        for ($i = 0; $i < $queries; $i++) {
            $area = $areas[($cursor + $i) % count($areas)];
            $this->line("<fg=gray>· sourcing {$area}</>");

            $this->callSilently('eye:source-leads', array_filter([
                '--user' => $userId,
                '--provider' => config('outreach.provider', 'places'),
                '--area' => $area,
                '--category' => config('outreach.category', 'ecommerce'),
                '--limit' => 20,
                '--dry-run' => $dryRun,
            ], fn ($v) => $v !== false));
        }

        if (!$dryRun) {
            Cache::forever(self::AREA_CURSOR, $cursor + $queries);
        }

        $this->call('eye:draft-outreach', array_filter([
            '--user' => $userId,
            '--limit' => $target,
            '--dry-run' => $dryRun,
        ], fn ($v) => $v !== false));

        $after = $this->draftCount($userId);
        $this->info(sprintf(
            '%d drafts pending review (%+d this run). Leads with no contact address stay for a later pass.',
            $after,
            $after - $before
        ));

        return self::SUCCESS;
    }

    private function draftCount(int $userId): int
    {
        return OutreachEmail::where('user_id', $userId)->where('status', 'draft')->count();
    }
}
