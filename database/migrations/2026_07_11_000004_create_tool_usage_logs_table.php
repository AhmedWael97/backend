<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which URLs prospects run through the free tools — a lead signal (checking
 * your own domain's speed/SEO = buying intent) as much as usage analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tool', 40); // speed_checker | seo_checker | sitemap_creator
            $table->string('url', 2048);
            $table->string('checked_host', 255)->nullable();
            $table->integer('score')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tool', 'created_at']);
            $table->index('checked_host');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_usage_logs');
    }
};
