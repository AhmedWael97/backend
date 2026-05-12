<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'paymob' to the payment_methods.type allowed values.
 *
 * Laravel's $table->enum() on PostgreSQL is implemented as a VARCHAR column
 * with a CHECK constraint. To expand the allowed values we must drop the old
 * CHECK constraint and add a new one that includes 'paymob'.
 */
return new class extends Migration {
    public function up(): void
    {
        // Drop the auto-generated CHECK constraint and replace it with an
        // expanded one that includes 'paymob'.
        DB::statement("
            ALTER TABLE payment_methods
                DROP CONSTRAINT IF EXISTS payment_methods_type_check
        ");

        DB::statement("
            ALTER TABLE payment_methods
                ADD CONSTRAINT payment_methods_type_check
                CHECK (type IN ('stripe', 'paypal', 'manual', 'bank_transfer', 'paymob'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE payment_methods
                DROP CONSTRAINT IF EXISTS payment_methods_type_check
        ");

        DB::statement("
            ALTER TABLE payment_methods
                ADD CONSTRAINT payment_methods_type_check
                CHECK (type IN ('stripe', 'paypal', 'manual', 'bank_transfer'))
        ");
    }
};
