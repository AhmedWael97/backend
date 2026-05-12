<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add payment_method.create / update / delete actions to the audit_logs CHECK constraint.
 *
 * These actions are recorded by AdminPaymentMethodController when an admin
 * creates, updates, or disables/enables a payment gateway in the super-admin panel.
 */
return new class extends Migration {
    public function up(): void
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
                'domain.delete'
            ]::text[]))
        ");
    }
};
