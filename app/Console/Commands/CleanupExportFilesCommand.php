<?php

namespace App\Console\Commands;

use App\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupExportFilesCommand extends Command
{
    protected $signature = 'eye:cleanup-exports';
    protected $description = 'Delete export files older than 24 hours.';

    public function handle(): void
    {
        $old = ExportJob::where('status', 'done')
            ->where('created_at', '<', now()->subHours(24))
            ->whereNotNull('file_path')
            ->get();

        foreach ($old as $export) {
            Storage::delete($export->file_path);
            $export->update(['file_path' => null, 'status' => 'expired']);
        }

        $this->line("Cleaned {$old->count()} export files.");
    }
}
