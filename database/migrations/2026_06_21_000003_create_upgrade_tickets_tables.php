<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan-upgrade support tickets.
 *
 * A user requests a plan upgrade (manual path while the payment gateway is
 * unavailable). This opens a ticket + a chat thread between the user and the
 * super-admin; both can attach files. The admin discusses, then applies the
 * plan directly from the ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('subject', 160);
            // open | pending_user | resolved | closed
            $table->string('status', 16)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        Schema::create('upgrade_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upgrade_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_system')->default(false); // e.g. "Plan applied"
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->timestamps();
            $table->index('upgrade_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_ticket_messages');
        Schema::dropIfExists('upgrade_tickets');
    }
};
