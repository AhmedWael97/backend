<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Flips subscriptions whose `current_period_end` has elapsed to `expired`.
 *
 * Without this, the User::activeSubscription scope would already filter them
 * out at read time (so limits fall through correctly), but the row's `status`
 * column would still say "active" — which is confusing to admins and breaks
 * any admin-side reporting that groups by status.
 *
 * Schedule:
 *   $schedule->command('subscriptions:expire')->dailyAt('00:05');
 */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';
    protected $description = 'Mark subscriptions past their period end as expired';

    public function handle(): int
    {
        $now = now();

        $expiringSubs = Subscription::where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->get();

        $count = 0;
        foreach ($expiringSubs as $sub) {
            $sub->update(['status' => 'expired']);
            $count++;

            // Best-effort audit log entry; skip if no admin user available.
            $systemAdmin = User::where('role', 'superadmin')->orderBy('id')->first();
            if ($systemAdmin) {
                try {
                    AuditLog::create([
                        'admin_id' => $systemAdmin->id,
                        'action' => 'subscription.expire',
                        'target_type' => 'Subscription',
                        'target_id' => $sub->id,
                        'before' => ['status' => 'active'],
                        'after' => ['status' => 'expired'],
                        'ip' => null,
                        'user_agent' => 'scheduler:subscriptions:expire',
                    ]);
                } catch (\Throwable $e) {
                    // Constraint might reject the action name on older databases;
                    // don't let audit-logging failure block the expiry itself.
                    $this->warn("Audit log write failed for subscription {$sub->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Expired {$count} subscription(s).");
        return self::SUCCESS;
    }
}
