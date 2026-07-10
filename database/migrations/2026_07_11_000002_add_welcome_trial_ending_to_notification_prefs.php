<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * notification_preferences was missing 'welcome' entirely (present in
 * notifications but never added to the preferences CHECK) — unreachable until
 * now because WelcomeMail was never actually sent, so nobody could click its
 * unsubscribe link. Also adds 'trial_ending' for the new reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN (
            'alert','quota_warning','export_ready','script_detected','welcome','subscription_changed','weekly_digest','daily_digest','trial_ending'
        ))");

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_type_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (type IN (
            'alert','quota_warning','export_ready','script_detected','welcome','subscription_changed','weekly_digest','daily_digest','trial_ending'
        ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN (
            'alert','quota_warning','export_ready','script_detected','welcome','subscription_changed','weekly_digest','daily_digest'
        ))");

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_type_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (type IN (
            'alert','quota_warning','export_ready','script_detected','subscription_changed','weekly_digest','daily_digest'
        ))");
    }
};
