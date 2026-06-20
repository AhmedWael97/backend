<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            // 'ab' = same URL, inject JS/CSS per variation; 'split_url' = redirect to other URLs.
            $table->string('type', 16)->default('ab')->after('name');
            $table->text('target_url')->nullable()->after('type');   // the control page URL
            $table->string('goal_type', 16)->default('purchase')->after('target_url'); // purchase | event | url
            $table->string('goal_value', 255)->nullable()->after('goal_type');         // event name or url pattern
            $table->string('status', 16)->default('draft')->after('goal_value');        // draft | running | paused
        });

        Schema::create('experiment_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('vkey', 32);                 // control, v1, v2, …
            $table->string('name', 120);
            $table->unsignedTinyInteger('weight')->default(0); // traffic % (0–100)
            $table->boolean('is_control')->default(false);
            $table->text('js_code')->nullable();        // A/B: injected JS
            $table->text('css_code')->nullable();       // A/B: injected CSS
            $table->text('redirect_url')->nullable();   // split_url: destination
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['experiment_id', 'vkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_variations');
        Schema::table('experiments', function (Blueprint $table) {
            $table->dropColumn(['type', 'target_url', 'goal_type', 'goal_value', 'status']);
        });
    }
};
