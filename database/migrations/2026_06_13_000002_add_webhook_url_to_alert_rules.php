<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            // Slack / Discord incoming-webhook URL, used when channel = slack|discord.
            $table->string('webhook_url', 1024)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('webhook_url');
        });
    }
};
