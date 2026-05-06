<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // PostgreSQL stores the enum as a CHECK constraint.
        // We must drop the old constraint and add a new one that includes
        // the 'user.grant_ai_tokens' value.
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
