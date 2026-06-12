<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Matches the campaign "source" / "campaign" classification shown in
            // the Campaigns dashboard (e.g. source "Facebook", campaign "summer_sale").
            $table->string('source', 120);
            $table->string('campaign', 200)->default('(none)');
            $table->string('medium', 60)->nullable();
            $table->decimal('spend', 14, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->unsignedInteger('clicks')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->timestamps();

            // One spend figure per source+campaign per day — re-imports upsert.
            $table->unique(['domain_id', 'date', 'source', 'campaign'], 'ad_spend_unique');
            $table->index(['domain_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend');
    }
};
