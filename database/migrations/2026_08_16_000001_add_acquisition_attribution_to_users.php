<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures first-touch ad attribution at registration — utm_source/medium/
 * campaign plus the ad platform's own click id (gclid/ttclid/fbclid), read
 * from a cookie set on first landing. Without this, a registered user can
 * never be traced back to the ad session that brought them (ClickHouse's
 * post-login identified visitor_id never carries the pre-login anonymous
 * session's utm values — confirmed while building the funnel export).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_utm_source', 100)->nullable()->after('referral_code');
            $table->string('signup_utm_medium', 100)->nullable()->after('signup_utm_source');
            $table->string('signup_utm_campaign', 255)->nullable()->after('signup_utm_medium');
            $table->string('signup_click_id', 255)->nullable()->after('signup_utm_campaign');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signup_utm_source', 'signup_utm_medium', 'signup_utm_campaign', 'signup_click_id']);
        });
    }
};
