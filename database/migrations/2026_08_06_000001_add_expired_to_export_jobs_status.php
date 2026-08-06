<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'expired' to the export_jobs.status allowed values.
 *
 * CleanupExportFilesCommand (eye:cleanup-exports, hourly) deletes files
 * older than 24h and marks the row 'expired' — a value the original enum
 * never included, so every run since has failed with a CHECK constraint
 * violation (SQLSTATE 23514) and the row's file_path was never cleared.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE export_jobs
                DROP CONSTRAINT IF EXISTS export_jobs_status_check
        ");

        DB::statement("
            ALTER TABLE export_jobs
                ADD CONSTRAINT export_jobs_status_check
                CHECK (status IN ('pending', 'processing', 'done', 'failed', 'expired'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE export_jobs
                DROP CONSTRAINT IF EXISTS export_jobs_status_check
        ");

        DB::statement("
            ALTER TABLE export_jobs
                ADD CONSTRAINT export_jobs_status_check
                CHECK (status IN ('pending', 'processing', 'done', 'failed'))
        ");
    }
};
