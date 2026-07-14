<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified inbox for the social-manager Chrome extension. Content scripts
 * scrape visible comments/DMs/mentions from a page the user has open and
 * logged into themselves (their own session, in their own browser — we never
 * hold their platform credentials or cookies) and push them here. The
 * extension/dashboard then reads this table, can AI-draft a reply, and sends
 * the drafted text back down to the content script to fill the native
 * compose box on that open tab — the user still sends it themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20); // facebook | x | instagram
            $table->string('item_type', 20); // comment | dm | mention
            $table->string('external_id'); // platform-side id/hash, for dedupe
            $table->string('author_name')->nullable();
            $table->string('author_handle')->nullable();
            $table->text('message')->nullable();
            $table->string('page_url', 2048)->nullable();
            $table->string('status', 20)->default('unread'); // unread | read | drafted | replied | dismissed
            $table->text('draft_reply')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'external_id']);
            $table->index(['user_id', 'status']);
        });

        // Postgres enum-as-CHECK-constraint pattern (see claude.md §11).
        \DB::statement("ALTER TABLE social_inbox_items ADD CONSTRAINT social_inbox_items_platform_check CHECK (platform IN ('facebook','x','instagram'))");
        \DB::statement("ALTER TABLE social_inbox_items ADD CONSTRAINT social_inbox_items_item_type_check CHECK (item_type IN ('comment','dm','mention'))");
        \DB::statement("ALTER TABLE social_inbox_items ADD CONSTRAINT social_inbox_items_status_check CHECK (status IN ('unread','read','drafted','replied','dismissed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('social_inbox_items');
    }
};
