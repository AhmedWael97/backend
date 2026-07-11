<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guard column for the abandoned-checkout nudge (eye:nudge-abandoned-checkouts):
 * a pending Payment (Paymob iframe opened, never completed) gets emailed at
 * most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('abandoned_nudge_sent_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('abandoned_nudge_sent_at');
        });
    }
};
