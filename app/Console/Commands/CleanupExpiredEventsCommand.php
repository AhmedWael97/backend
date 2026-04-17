<?php

namespace App\Console\Commands;

use App\Services\ClickHouseService;
use App\Models\Domain;
use Illuminate\Console\Command;

class CleanupExpiredEventsCommand extends Command
{
    protected $signature = 'eye:cleanup-events';
    protected $description = 'Delete ClickHouse events older than each domain plan data_retention_days.';

    public function handle(ClickHouseService $clickhouse): void
    {
        Domain::with('user.subscription.plan')
            ->where('active', true)
            ->chunk(100, function ($domains) use ($clickhouse) {
                foreach ($domains as $domain) {
                    $days = $domain->user?->subscription?->plan?->getLimit('data_retention_days', 30) ?? 30;
                    $id = $domain->id;

                    foreach (['events', 'sessions', 'ux_events', 'pipeline_events', 'custom_events'] as $table) {
                        try {
                            $clickhouse->statement(
                                "ALTER TABLE {$table} DELETE WHERE domain_id = {$id} AND created_at < now() - INTERVAL {$days} DAY"
                            );
                        } catch (\Throwable $e) {
                            $this->error("Failed to clean {$table} for domain {$id}: " . $e->getMessage());
                        }
                    }
                    $this->line("Cleaned domain #{$id} (retention: {$days}d)");
                }
            });
    }
}
