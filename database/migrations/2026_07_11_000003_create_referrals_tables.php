<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-sided referral loop: whoever invited + whoever was invited both get a
 * trial extension once the referred account proves it's real (verified email
 * + at least one connected domain) — same anti-abuse bar as the agency-plan
 * gate, applied here to keep the reward from being farmable by throwaway signups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->unique()->after('api_key');
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | rewarded
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->unique('referred_user_id'); // one referrer credited per new account
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('referral_code');
        });
    }
};
