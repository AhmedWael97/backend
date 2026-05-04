<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bot_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('hits')->default(0);
            $table->unique(['domain_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_hits');
    }
};
