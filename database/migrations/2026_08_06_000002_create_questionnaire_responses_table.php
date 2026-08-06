<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('questionnaire_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role'); // site_owner | marketer
            $table->unsignedInteger('sites_managed')->default(0);
            $table->json('languages')->nullable();
            $table->json('features')->nullable();
            $table->json('domains')->nullable(); // [{domain, seo_score, speed_score, pages_found}]
            $table->foreignId('plan_assigned_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_responses');
    }
};
