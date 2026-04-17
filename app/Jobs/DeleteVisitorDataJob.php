<?php

namespace App\Jobs;

use App\Models\DataDeletionRequest;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteVisitorDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $deletionRequestId)
    {
    }

    public function handle(ClickHouseService $clickhouse): void
    {
        $request = DataDeletionRequest::findOrFail($this->deletionRequestId);
        $domainId = $request->domain_id;
        $visitorId = $request->visitor_id;

        foreach (['events', 'sessions', 'ux_events', 'pipeline_events', 'custom_events'] as $table) {
            $clickhouse->statement(
                "ALTER TABLE {$table} DELETE WHERE domain_id = {$domainId} AND visitor_id = '{$visitorId}'"
            );
        }

        $request->update([
            'processed_at' => now(),
            'status' => 'done',
        ]);
    }
}
