<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the remaining user.* actions to the audit_logs CHECK constraint so that
 * super-admin actions (create, delete, verify_email, disable_2fa, toggle_admin)
 * can be recorded.
 *
 * Without this, AdminUserController::destroy and related methods fail with:
 *   SQLSTATE[23514]: new row for relation "audit_logs" violates check constraint
 */
return new class extends Migration {
    private array $allowed = [
        'user.create',
        'user.block',
        'user.unblock',
        'user.edit',
        'user.delete',
        'user.verify_email',
        'user.disable_2fa',
        'user.toggle_admin',
        'user.grant_ai_tokens',
        'impersonate.start',
        'impersonate.end',
        'plan.create',
        'plan.edit',
        'plan.delete',
        'subscription.change',
        'subscription.cancel',
        'payment.refund',
        'domain.delete',
        'payment_method.create',
        'payment_method.update',
        'payment_method.delete',
    ];

    public function up(): void
    {
        $list = "'" . implode("','", $this->allowed) . "'";

        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_action_check');
        DB::statement("
            ALTER TABLE audit_logs
            ADD CONSTRAINT audit_logs_action_check
            CHECK (action::text = ANY (ARRAY[{$list}]::text[]))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_action_check');
        DB::statement("
            ALTER TABLE audit_logs
            ADD CONSTRAINT audit_logs_action_check
            CHECK (action::text = ANY (ARRAY[
                'user.block',
                'user.unblock',
                'user.edit',
                'user.grant_ai_tokens',
                'impersonate.start',
                'impersonate.end',
                'plan.create',
                'plan.edit',
                'plan.delete',
                'subscription.change',
                'subscription.cancel',
                'payment.refund',
                'domain.delete',
                'payment_method.create',
                'payment_method.update',
                'payment_method.delete'
            ]::text[]))
        ");
    }
};
