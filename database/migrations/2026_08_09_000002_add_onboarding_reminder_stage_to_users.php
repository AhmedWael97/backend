<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the one-shot "you're one step away" nudge into a 3-part drip
 * (Day 1 / 3 / 5). `onboarding_reminder_sent_at` becomes "last stage sent
 * at" instead of a single fire-once marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('onboarding_reminder_stage')->default(0)->after('onboarding_reminder_sent_at');
        });

        // Anyone who already got the old single email is at stage 1 (Day 1
        // equivalent) — the drip continues from Day 3 for them, not restarts.
        DB::table('users')->whereNotNull('onboarding_reminder_sent_at')->update(['onboarding_reminder_stage' => 1]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_reminder_stage');
        });
    }
};
