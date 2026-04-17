<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public readonly int $exportId)
    {
    }

    public function handle(ClickHouseService $clickhouse): void
    {
        $export = ExportJob::findOrFail($this->exportId);
        $export->update(['status' => 'processing']);

        try {
            $filters = $export->filters ?? [];
            $from = $filters['from'] ?? now()->subDays(30)->format('Y-m-d');
            $to = $filters['to'] ?? now()->format('Y-m-d');
            $domainId = $export->domain_id;

            $rows = match ($export->type) {
                'events' => $clickhouse->select("
                    SELECT type, url, country, device_type, browser, os, ts
                    FROM events
                    WHERE domain_id = {$domainId}
                      AND ts >= '{$from} 00:00:00' AND ts < '{$to} 23:59:59'
                    ORDER BY ts DESC LIMIT 100000
                "),
                'visitors' => $clickhouse->select("
                    SELECT visitor_id, country, device_type, browser, os,
                           count() AS events, max(ts) AS last_seen
                    FROM events
                    WHERE domain_id = {$domainId}
                      AND ts >= '{$from} 00:00:00' AND ts < '{$to} 23:59:59'
                    GROUP BY visitor_id, country, device_type, browser, os
                    ORDER BY last_seen DESC LIMIT 100000
                "),
                'funnel' => $clickhouse->select("
                    SELECT pipeline_id, step_id, status, count() AS count
                    FROM pipeline_events
                    WHERE domain_id = {$domainId}
                      AND event_time >= '{$from} 00:00:00' AND event_time < '{$to} 23:59:59'
                    GROUP BY pipeline_id, step_id, status
                "),
                default => [],
            };

            $filename = "exports/{$export->user_id}/export_{$export->id}.csv";
            $csv = $this->buildCsv($rows);
            Storage::put($filename, $csv);

            $export->update([
                'status' => 'done',
                'file_path' => $filename,
            ]);
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed']);
            throw $e;
        }
    }

    private function buildCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
