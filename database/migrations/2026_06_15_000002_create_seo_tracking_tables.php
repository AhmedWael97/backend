<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 200);
            $table->timestamps();
            $table->unique(['domain_id', 'keyword']);
        });

        Schema::create('seo_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 200);
            $table->date('date');
            $table->unsignedSmallInteger('position')->nullable(); // 1 = top; null = not ranking
            $table->string('url', 2048)->nullable();
            $table->timestamps();
            // One position per keyword per day — re-imports upsert.
            $table->unique(['domain_id', 'keyword', 'date'], 'seo_rankings_unique');
            $table->index(['domain_id', 'keyword', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rankings');
        Schema::dropIfExists('seo_keywords');
    }
};
