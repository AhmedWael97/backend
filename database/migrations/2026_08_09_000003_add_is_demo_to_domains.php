<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shared, real domain flagged is_demo=true — a Stripe/Paymob-style
 * sandbox. Any authenticated user can select it and browse every real
 * dashboard page against real (seeded) data, with zero per-page mocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('active');
            $table->index('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
