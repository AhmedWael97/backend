<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->enum('action', [
                'user.block',
                'user.unblock',
                'user.edit',
                'impersonate.start',
                'impersonate.end',
                'plan.create',
                'plan.edit',
                'plan.delete',
                'subscription.change',
                'subscription.cancel',
                'payment.refund',
                'domain.delete',
            ]);
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('theme_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Phase 2 chatbot tables (schema ready, feature disabled in Phase 1)
        Schema::create('chatbot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->json('context_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('chatbot_sessions')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamps();
        });

        Schema::create('website_chatbot_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('bot_name')->default('Assistant');
            $table->text('welcome_message')->nullable();
            $table->string('color', 7)->default('#6366f1');
            $table->enum('position', ['bottom-right', 'bottom-left'])->default('bottom-right');
            $table->text('system_prompt')->nullable();
            $table->json('knowledge_base')->nullable();
            $table->timestamps();
        });

        Schema::create('website_chatbot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 36)->index();
            $table->string('session_id', 36)->index();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });

        Schema::create('website_chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('website_chatbot_conversations')->cascadeOnDelete();
            $table->enum('role', ['visitor', 'bot']);
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_chatbot_messages');
        Schema::dropIfExists('website_chatbot_conversations');
        Schema::dropIfExists('website_chatbot_configs');
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_sessions');
        Schema::dropIfExists('theme_settings');
        Schema::dropIfExists('audit_logs');
    }
};
