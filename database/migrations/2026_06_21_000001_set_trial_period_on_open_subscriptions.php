<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the new 30-day trial window.
 *
 * Before this change, free subscriptions were created with a NULL
 * `current_period_end`, so they were "active" forever. Now that the
 * `subscribed` middleware blocks features once the period ends, give every
 * existing open (active, no end date) subscription a fresh 30-day window from
 * deploy time so no current user is suddenly locked out. Paid subscriptions
 * already carry an explicit period end and are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('status', 'active')
            ->whereNull('current_period_end')
            ->update([
                'current_period_end' => now()->addDays(30),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No-op: we cannot reliably distinguish backfilled rows on rollback,
        // and clearing period ends would re-open expired trials.
    }
};
