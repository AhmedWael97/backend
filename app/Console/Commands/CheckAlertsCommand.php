<?php

namespace App\Console\Commands;

use App\Jobs\CheckAlertRulesJob;
use App\Models\AlertRule;
use Illuminate\Console\Command;

/**
 * Evaluate active alert rules for every domain that has them and dispatch a
 * CheckAlertRulesJob per domain (which notifies on breaches).
 *
 * Scheduled every 15 minutes (see routes/console.php). The job applies a
 * per-rule cooldown so a persistently-breached rule won't spam notifications.
 *
 * Usage:
 *   php artisan eye:check-alerts
 */
class CheckAlertsCommand extends Command
{
    protected $signature = 'eye:check-alerts';

    protected $description = 'Evaluate active alert rules for every domain and notify on breaches.';

    public function handle(): int
    {
        $domainIds = AlertRule::where('is_active', true)
            ->distinct()
            ->pluck('domain_id');

        foreach ($domainIds as $domainId) {
            CheckAlertRulesJob::dispatch((int) $domainId)->onQueue('notifications');
        }

        $this->info("Dispatched alert checks for {$domainIds->count()} domain(s).");

        return self::SUCCESS;
    }
}
