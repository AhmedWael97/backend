<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The AI report prompt was upgraded to emit richer suggestion categories
 * (acquisition / retention / measurement). The old CHECK constraint only allowed
 * audience|marketing|ux|conversion, so AnalyzeDomainJob threw on save and no AI
 * report has persisted since. Widen the allowed set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_suggestions DROP CONSTRAINT IF EXISTS ai_suggestions_category_check');
        DB::statement("ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_category_check CHECK (category IN ('audience','marketing','ux','conversion','acquisition','retention','measurement'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_suggestions DROP CONSTRAINT IF EXISTS ai_suggestions_category_check');
        DB::statement("ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_category_check CHECK (category IN ('audience','marketing','ux','conversion'))");
    }
};
