<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The support bubble now lives on the public marketing site too, where visitors
 * have no account. A guest chat is identified by a random token the browser keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Existing rows all belong to a user; new guest rows have no user_id.
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE support_chats ALTER COLUMN user_id DROP NOT NULL');

        Schema::table('support_chats', function (Blueprint $table) {
            $table->string('guest_token', 64)->nullable()->unique();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_chats', function (Blueprint $table) {
            $table->dropColumn(['guest_token', 'guest_name', 'guest_email']);
        });
    }
};
