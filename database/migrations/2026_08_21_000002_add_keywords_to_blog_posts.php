<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-post SEO keywords. Every blog post was inheriting the same site-wide
 * DEFAULT_KEYWORDS meta tag (no per-post override existed) — identical
 * keyword signals across every post dilutes topical relevance instead of
 * helping it. These are generated per post, from its actual topic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->string('keywords_en', 500)->nullable()->after('excerpt_ar');
            $table->string('keywords_ar', 500)->nullable()->after('keywords_en');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['keywords_en', 'keywords_ar']);
        });
    }
};
