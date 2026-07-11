<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NPS ("how likely to recommend", 0-10) — distinct from the existing
 * `feedback` table (1-4 CSAT rating asked once right after signup). This
 * fires later, after ~14 days of real usage, and is the intended source for
 * honest testimonials + an early churn/advocacy signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 0-10
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('nps_prompted_at')->nullable()->after('trial_tips_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nps_prompted_at');
        });
        Schema::dropIfExists('nps_responses');
    }
};
