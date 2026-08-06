<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track partial/abandoned "get started" quiz attempts, not just completed
 * ones — every step is autosaved keyed by a per-browser visitor_id, so the
 * superadmin can see who started and how far they got even if they never
 * finish.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table) {
            $table->string('visitor_id')->nullable()->after('user_id')->index();
            $table->boolean('completed')->default(false)->after('domains');
            $table->unsignedTinyInteger('step_reached')->default(0)->after('completed');
        });

        // role/sites_managed were NOT NULL — a partial save may not have
        // reached them yet.
        DB::statement('ALTER TABLE questionnaire_responses ALTER COLUMN role DROP NOT NULL');
        DB::statement('ALTER TABLE questionnaire_responses ALTER COLUMN sites_managed DROP NOT NULL');
        DB::statement('ALTER TABLE questionnaire_responses ALTER COLUMN sites_managed DROP DEFAULT');
    }

    public function down(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table) {
            $table->dropColumn(['visitor_id', 'completed', 'step_reached']);
        });
        DB::statement("UPDATE questionnaire_responses SET role = 'site_owner' WHERE role IS NULL");
        DB::statement('ALTER TABLE questionnaire_responses ALTER COLUMN role SET NOT NULL');
        DB::statement('ALTER TABLE questionnaire_responses ALTER COLUMN sites_managed SET DEFAULT 0');
        DB::statement('UPDATE questionnaire_responses SET sites_managed = 0 WHERE sites_managed IS NULL');
        DB::statement('ALTER TABLE questionnaire_responses ALTER COLUMN sites_managed SET NOT NULL');
    }
};
