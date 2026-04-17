<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('content');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['domain_id', 'generated_at']);
        });

        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->enum('category', ['audience', 'marketing', 'ux', 'conversion']);
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->boolean('is_dismissed')->default(false);
            $table->timestamps();

            $table->index(['domain_id', 'is_dismissed']);
        });

        Schema::create('audience_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('rules')->nullable();
            $table->unsignedInteger('visitor_count')->default(0);
            $table->string('color', 7)->default('#6366f1');
            $table->timestamps();
        });

        Schema::create('ux_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 36);
            $table->string('visitor_id', 36);
            $table->enum('type', ['js_error', 'dead_click', 'rage_click', 'broken_link', 'hesitation', 'form_abandon']);
            $table->string('url');
            $table->string('element_selector')->nullable();
            $table->text('message')->nullable();
            $table->text('stack_trace')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'type', 'created_at']);
        });

        Schema::create('ux_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('breakdown');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['domain_id', 'calculated_at']);
        });

        Schema::create('visitor_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 36)->index();
            $table->string('external_id');
            $table->json('traits')->nullable();
            $table->timestamp('first_identified_at');
            $table->timestamp('updated_at');

            $table->unique(['domain_id', 'visitor_id']);
            $table->index(['domain_id', 'external_id']);
        });

        Schema::create('company_enrichments', function (Blueprint $table) {
            $table->id();
            $table->string('ip_hash', 64)->unique();
            $table->string('company_name')->nullable();
            $table->string('company_domain')->nullable();
            $table->string('industry')->nullable();
            $table->string('employee_range')->nullable();
            $table->string('country', 2)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('enriched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_enrichments');
        Schema::dropIfExists('visitor_identities');
        Schema::dropIfExists('ux_scores');
        Schema::dropIfExists('ux_issues');
        Schema::dropIfExists('audience_segments');
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('ai_reports');
    }
};
