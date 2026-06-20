<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mini-CRM of prospects (warm from EYE's own company-visitor data, or imported).
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('source', 20)->default('manual');  // visitor | import | manual
            $table->string('status', 20)->default('new');      // new | contacted | replied | won | lost
            $table->unsignedSmallInteger('score')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        // Global opt-out / bounce list — checked before every send.
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('reason', 20)->default('unsubscribe'); // unsubscribe | bounce | complaint | manual
            $table->timestamps();
            $table->unique(['user_id', 'email']);
        });

        // Record of every outreach email sent (for audit + unsubscribe handling).
        Schema::create('outreach_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_email');
            $table->string('subject');
            $table->text('body');
            $table->string('status', 20)->default('sent');     // sent | failed | skipped
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_emails');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('leads');
    }
};
