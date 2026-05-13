<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds payment.approve and subscription.expire actions to the audit_logs
 * CHECK constraint. payment.approve is fired when an admin verifies a pending
 * payment (and AI tokens are credited / a subscription is activated).
 * subscription.expire is fired by the scheduled expiry command.
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
        'subscription.expire',
        'payment.refund',
        'payment.approve',
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
        // No-op down — leaving the previous (broader) constraint in place is safe.
    }
};
