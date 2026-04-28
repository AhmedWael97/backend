<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('session_replays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->string('visitor_id', 64);
            $table->text('start_url')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->enum('status', ['recording', 'complete', 'pruned'])->default('recording');
            $table->timestamp('recorded_at')->useCurrent();

            // One replay record per session per domain.
            $table->unique(['domain_id', 'session_id']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_replays');
    }
};
