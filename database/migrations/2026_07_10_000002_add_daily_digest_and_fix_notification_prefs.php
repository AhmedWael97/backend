<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'daily_digest' to the notification/notification_preferences type CHECK
 * constraints (Postgres enum = VARCHAR + CHECK, per convention — widen by
 * drop+recreate).
 *
 * Also fixes a dead feature: no code path ever created notification_preferences
 * rows, so SendWeeklyDigestCommand's opt-in query (`where('email', true)`)
 * always matched zero users — the weekly digest has never actually sent. The
 * commands now treat "no row" as opted-in for weekly (matches the settings
 * page's default-on toggle) and opted-out for the new daily option, so this
 * migration doesn't need to backfill anything.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN (
            'alert','quota_warning','export_ready','script_detected','welcome','subscription_changed','weekly_digest'
        ))");

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_type_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (type IN (
            'alert','quota_warning','export_ready','script_detected','subscription_changed','weekly_digest'
        ))");
    }
};
