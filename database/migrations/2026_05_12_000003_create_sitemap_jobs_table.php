<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sitemap_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('start_url', 2048);
            $table->string('status', 20)->default('pending');  // pending|crawling|enriching|analyzing|completed|failed
            $table->json('config')->nullable();                 // {max_pages, include_zero_traffic, include_analytics_only, date_range_days}
            $table->json('crawl_result')->nullable();           // [{url, status_code, depth, title, canonical, last_modified}]
            $table->json('analytics_result')->nullable();       // [{url, pageviews, unique_visitors, entry_count, avg_depth, traffic_label}]
            $table->json('sitemap_result')->nullable();         // final merged array with priority, changefreq, etc.
            $table->json('ai_analysis')->nullable();            // {site_type, strategy, priority_rules, changefreq_rules, recommendations[]}
            $table->longText('sitemap_xml')->nullable();        // final XML string
            $table->integer('pages_crawled')->default(0);
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['domain_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitemap_jobs');
    }
};
