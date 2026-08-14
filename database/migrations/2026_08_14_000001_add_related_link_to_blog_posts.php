<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            // Optional single internal CTA link rendered at the end of the post
            // (e.g. "See how heatmaps work" -> /heatmaps). Kept as its own
            // column — separate from freeform body text — so the URL is always
            // one we set programmatically, never something an LLM could
            // hallucinate into a broken link.
            $table->string('related_url')->nullable()->after('body_ar');
            $table->string('related_label')->nullable()->after('related_url');
            $table->string('related_label_ar')->nullable()->after('related_label');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['related_url', 'related_label', 'related_label_ar']);
        });
    }
};
