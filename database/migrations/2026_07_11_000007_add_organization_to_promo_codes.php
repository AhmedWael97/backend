<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency self-serve referral codes: an org owner/admin gets one auto-generated
 * promo code to hand their own clients, riding the same discount/redemption
 * rails as admin-created codes — just scoped to an organization instead of
 * created by a superadmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('created_by')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
