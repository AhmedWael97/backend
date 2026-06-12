<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);            // stable identifier used in EYE.experiment()
            $table->string('name', 200);
            $table->json('variants');             // ["control", "variant_b", …] — first is control
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['domain_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiments');
    }
};
