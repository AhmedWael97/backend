<?php

namespace App\Console\Commands;

use App\Jobs\DeleteVisitorDataJob;
use App\Models\DataDeletionRequest;
use Illuminate\Console\Command;

class ProcessDataDeletionRequestsCommand extends Command
{
    protected $signature = 'eye:process-deletions';
    protected $description = 'Dispatch DeleteVisitorDataJob for pending data deletion requests.';

    public function handle(): void
    {
        DataDeletionRequest::where('status', 'pending')
            ->chunk(50, function ($requests) {
                foreach ($requests as $req) {
                    DeleteVisitorDataJob::dispatch($req->id)->onQueue('default');
                }
            });
    }
}
