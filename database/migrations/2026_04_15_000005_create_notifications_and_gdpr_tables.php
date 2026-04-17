<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['traffic_drop', 'error_spike', 'quota_warning', 'score_drop']);
            $table->json('threshold');
            $table->enum('channel', ['in_app', 'email', 'both'])->default('both');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', [
                'alert',
                'quota_warning',
                'export_ready',
                'script_detected',
                'welcome',
                'subscription_changed',
                'weekly_digest',
            ]);
            $table->string('title');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->enum('channel', ['in_app', 'email', 'both'])->default('in_app');
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'alert',
                'quota_warning',
                'export_ready',
                'script_detected',
                'subscription_changed',
                'weekly_digest',
            ]);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('visitor_optouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 36);
            $table->timestamp('opted_out_at');

            $table->unique(['domain_id', 'visitor_id']);
        });

        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 36);
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->enum('status', ['pending', 'done'])->default('pending');

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
        Schema::dropIfExists('visitor_optouts');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('alert_rules');
    }
};
