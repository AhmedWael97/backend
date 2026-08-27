<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured payload behind an outreach draft: the host, the audit score, the
 * verified issues, the CTA link and the pricing line.
 *
 * The plain-text `body` stays the source of truth for what the message says —
 * it is what a human reviews and edits. This column exists so the renderer can
 * lay the same content out as a designed HTML email (score badge, issue cards,
 * a real button) instead of running nl2br over prose. Nullable: drafts written
 * before this, and anything composed by hand in the dashboard, still render
 * through the plain-text path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_emails', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('outreach_emails', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
