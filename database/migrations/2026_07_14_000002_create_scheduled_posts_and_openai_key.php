<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social composer: AI-drafted (text via Claude, image via the user's own
 * OpenAI key — Claude doesn't generate images) posts queued for the Chrome
 * extension to fill into the platform's compose box next time that
 * platform's tab is open. No server-side auto-publish — see extension
 * README for why (no official API integration yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20); // facebook | x | instagram
            $table->string('language', 10)->default('en');
            $table->text('prompt')->nullable(); // what the user asked the AI for
            $table->text('content')->nullable(); // the generated/edited post text
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('scheduled_at');
            $table->string('status', 20)->default('queued'); // queued | filled | posted | cancelled
            $table->timestamps();

            $table->index(['user_id', 'status', 'scheduled_at']);
        });

        \DB::statement("ALTER TABLE scheduled_posts ADD CONSTRAINT scheduled_posts_platform_check CHECK (platform IN ('facebook','x','instagram'))");
        \DB::statement("ALTER TABLE scheduled_posts ADD CONSTRAINT scheduled_posts_status_check CHECK (status IN ('queued','filled','posted','cancelled'))");

        Schema::table('users', function (Blueprint $table) {
            $table->text('openai_api_key')->nullable()->after('api_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('openai_api_key');
        });
        Schema::dropIfExists('scheduled_posts');
    }
};
