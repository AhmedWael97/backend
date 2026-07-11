<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guard for the mid-trial feature-discovery email (eye:send-trial-tips) —
 * rounds out the drip: day 0 welcome -> day 2-3 no-domain nudge ->
 * day ~10 feature tips -> day ~25 trial-ending. Sent at most once per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_tips_sent_at')->nullable()->after('trial_ending_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_tips_sent_at');
        });
    }
};
