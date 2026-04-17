<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('script_token', 64)->unique();
            $table->string('previous_script_token', 64)->nullable()->index();
            $table->timestamp('token_rotated_at')->nullable();
            $table->timestamp('script_verified_at')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'domain']);
        });

        Schema::create('domain_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['ip', 'cookie', 'user_agent']);
            $table->string('value');
            $table->timestamps();

            $table->index(['domain_id', 'type']);
        });

        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('pipeline_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url_pattern');
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_steps');
        Schema::dropIfExists('pipelines');
        Schema::dropIfExists('domain_exclusions');
        Schema::dropIfExists('domains');
    }
};
