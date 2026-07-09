<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live customer-service chat: one open thread per user, answered by a superadmin.
 * Replaces the AI assistant bubble in the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('open'); // open | closed
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_for_admin')->default(0);
            $table->unsignedInteger('unread_for_user')->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index('last_message_at');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('support_chats')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_chats');
    }
};
