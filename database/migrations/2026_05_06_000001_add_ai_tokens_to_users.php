<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // How many AI analysis tokens the user has available.
            // Free plan users get 1 free run (gated by visitor count ≥ 1000),
            // tracked separately via ai_free_used.
            $table->unsignedInteger('ai_tokens')->default(0)->after('onboarding');

            // Tracks whether the free-plan once-off analysis has been consumed.
            $table->boolean('ai_free_used')->default(false)->after('ai_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_tokens', 'ai_free_used']);
        });
    }
};
