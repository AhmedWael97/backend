<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether EYE gets mentioned when an AI assistant is asked a real
 * buyer-intent question ("best Hotjar alternative", etc.) — the same idea
 * as classic rank tracking, but for AI answer engines instead of Google SERPs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_visibility_checks', function (Blueprint $table) {
            $table->id();
            $table->string('query', 255);
            $table->string('engine', 40)->default('claude');
            $table->boolean('mentioned')->default(false);
            $table->text('answer');
            $table->timestamp('checked_at');
            $table->index(['query', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_checks');
    }
};
