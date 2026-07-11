<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two additions to the notifications/notification_preferences CHECK:
 * - 'upgrade_ticket': already used by Admin\AdminUpgradeTicketController and
 *   UpgradeTicketController (NotificationService::send(..., 'upgrade_ticket', ...))
 *   but was never added to the CHECK constraint — every send has been throwing
 *   a constraint violation in production.
 * - 'tool_domain_suggestion': new, in-app-only (no mail map entry), used by
 *   eye:suggest-connect-checked-domains.
 */
return new class extends Migration
{
    private const OLD = "'alert','quota_warning','export_ready','script_detected','welcome','subscription_changed','weekly_digest','daily_digest','trial_ending'";
    private const NEW = "'alert','quota_warning','export_ready','script_detected','welcome','subscription_changed','weekly_digest','daily_digest','trial_ending','upgrade_ticket','tool_domain_suggestion'";

    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN (' . self::NEW . '))');

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_type_check');
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (type IN (' . self::NEW . '))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN (' . self::OLD . '))');

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_type_check');
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (type IN (' . self::OLD . '))');
    }
};
