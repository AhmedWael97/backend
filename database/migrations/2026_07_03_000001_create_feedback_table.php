<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-signup experience feedback (star rating + free-text). One row per user
 * (they're asked once); super-admin reads the aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1=Bad 2=Weak 3=Good 4=Excellent
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // asked once per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
