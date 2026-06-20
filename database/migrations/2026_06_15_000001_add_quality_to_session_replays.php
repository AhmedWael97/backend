<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('session_replays', function (Blueprint $table) {
            // Playability gate: only recordings with a valid FullSnapshot are shown.
            $table->boolean('has_snapshot')->default(false)->after('event_count');
            // Why the session was kept (rage_click, dead_click, js_error, engaged, …).
            $table->string('reason', 32)->nullable()->after('has_snapshot');
            $table->unsignedInteger('score')->default(0)->after('reason');
            $table->index(['domain_id', 'has_snapshot', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('session_replays', function (Blueprint $table) {
            $table->dropIndex(['domain_id', 'has_snapshot', 'recorded_at']);
            $table->dropColumn(['has_snapshot', 'reason', 'score']);
        });
    }
};
