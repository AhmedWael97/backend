<?php

namespace App\Console\Commands;

use App\Services\ClickHouseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipe all session replay data from both PostgreSQL and ClickHouse.
 *
 * Use this after a known-broken recording period (e.g. after a storage-format
 * bug) so that the dashboard no longer shows unplayable sessions.
 *
 * Usage:
 *   php artisan eye:purge-replay          # dry-run (shows counts, does nothing)
 *   php artisan eye:purge-replay --force  # actually deletes everything
 *   php artisan eye:purge-replay --domain=42 --force  # single domain only
 */
class PurgeReplayDataCommand extends Command
{
    protected $signature = 'eye:purge-replay
        {--domain= : Only purge data for this domain_id}
        {--force   : Actually delete; without this flag the command is a dry-run}';

    protected $description = 'Purge broken session replay data from PostgreSQL and ClickHouse.';

    public function handle(ClickHouseService $ch): int
    {
        $domainId = $this->option('domain') ? (int) $this->option('domain') : null;
        $force = (bool) $this->option('force');

        // ── Count rows to be deleted ─────────────────────────────────────────
        $pgQuery = DB::table('session_replays');
        if ($domainId) {
            $pgQuery->where('domain_id', $domainId);
        }
        $pgCount = $pgQuery->count();

        $chWhere = $domainId ? "WHERE domain_id = {$domainId}" : '';
        $chRows = $ch->select("SELECT count() AS cnt FROM replay_events {$chWhere}");
        $chCount = (int) ($chRows[0]['cnt'] ?? 0);

        $this->line('');
        $this->line(sprintf(
            '  PostgreSQL session_replays : <comment>%d</comment> row(s)',
            $pgCount
        ));
        $this->line(sprintf(
            '  ClickHouse replay_events   : <comment>%d</comment> row(s)',
            $chCount
        ));
        $this->line('');

        if (!$force) {
            $this->warn('DRY-RUN — no data was deleted. Re-run with --force to proceed.');
            return self::SUCCESS;
        }

        if (!$this->confirm('This will permanently delete all listed replay data. Continue?')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        // ── Delete from PostgreSQL ───────────────────────────────────────────
        $pgDelQuery = DB::table('session_replays');
        if ($domainId) {
            $pgDelQuery->where('domain_id', $domainId);
        }
        $deleted = $pgDelQuery->delete();
        $this->info("Deleted {$deleted} row(s) from session_replays.");

        // ── Delete from ClickHouse ───────────────────────────────────────────
        // ALTER TABLE … DELETE is a mutation (eventually consistent).
        // TRUNCATE is instant but only available when no domain filter is needed.
        if ($domainId) {
            $ch->execute("ALTER TABLE replay_events DELETE WHERE domain_id = {$domainId}");
            $this->info("Queued ClickHouse mutation to delete replay_events for domain {$domainId}.");
        } else {
            $ch->execute('TRUNCATE TABLE replay_events');
            $this->info('Truncated ClickHouse replay_events table.');
        }

        $this->line('');
        $this->info('Purge complete. New recordings will be clean going forward.');

        return self::SUCCESS;
    }
}
