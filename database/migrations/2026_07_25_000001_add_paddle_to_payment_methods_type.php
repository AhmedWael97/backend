<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'paddle' to the payment_methods.type allowed values (same
 * enum-as-CHECK-constraint pattern as the earlier Paymob migration).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE payment_methods
                DROP CONSTRAINT IF EXISTS payment_methods_type_check
        ");

        DB::statement("
            ALTER TABLE payment_methods
                ADD CONSTRAINT payment_methods_type_check
                CHECK (type IN ('stripe', 'paypal', 'manual', 'bank_transfer', 'paymob', 'paddle'))
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payment_methods DROP CONSTRAINT IF EXISTS payment_methods_type_check");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_check CHECK (type IN ('stripe', 'paypal', 'manual', 'bank_transfer', 'paymob'))");
    }
};
