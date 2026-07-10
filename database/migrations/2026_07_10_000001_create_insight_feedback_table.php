<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('page', 40);
            $table->string('kind', 60); // Finding kind, e.g. 'bounce_rootcause'
            $table->boolean('helpful');
            $table->timestamps();

            $table->unique(['user_id', 'domain_id', 'page', 'kind'], 'insight_feedback_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_feedback');
    }
};
