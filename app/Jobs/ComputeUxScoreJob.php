<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\UxScore;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComputeUxScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $domainId)
    {
    }

    public function handle(ClickHouseService $clickhouse): void
    {
        $from = now()->subDays(30)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');
        $id = $this->domainId;

        // UX event counts
        $ux = $clickhouse->select("
            SELECT type, count() AS c
            FROM ux_events
            WHERE domain_id = {$id}
              AND created_at >= '{$from}' AND created_at < '{$to}'
            GROUP BY type
        ");
        $uxMap = collect($ux)->pluck('c', 'type');

        // Total events for rate calculation
        $totalEventsRow = $clickhouse->select("
            SELECT count() AS c FROM events
            WHERE domain_id = {$id} AND ts >= '{$from}' AND ts < '{$to}'
        ");
        $totalEvents = max(1, (int) ($totalEventsRow[0]['c'] ?? 1));

        // Session stats
        $sessRow = $clickhouse->select("
            SELECT
                avg(duration_seconds) AS avg_duration,
                countIf(page_count = 1) / count() AS bounce_rate
            FROM sessions
            WHERE domain_id = {$id}
              AND started_at >= '{$from}' AND started_at < '{$to}'
        ");
        $avgDuration = (float) ($sessRow[0]['avg_duration'] ?? 0);
        $bounceRate = (float) ($sessRow[0]['bounce_rate'] ?? 0);

        // Compute sub-scores (0–100, higher = better)
        $errorRate = min((int) ($uxMap['js_error'] ?? 0) / $totalEvents * 1000, 1);
        $rageRate = min((int) ($uxMap['rage_click'] ?? 0) / $totalEvents * 200, 1);
        $formAbandon = min((int) ($uxMap['form_abandon'] ?? 0) / max($totalEvents / 10, 1), 1);
        $avgDurationSub = min($avgDuration / 180, 1); // 3 min = 100

        $errorScore = (int) round((1 - $errorRate) * 100);
        $rageScore = (int) round((1 - $rageRate) * 100);
        $formScore = (int) round((1 - $formAbandon) * 100);
        $durationScore = (int) round($avgDurationSub * 100);
        $bounceScore = (int) round((1 - $bounceRate) * 100);

        $overall = (int) round(
            ($errorScore * 0.25) +
            ($rageScore * 0.20) +
            ($formScore * 0.15) +
            ($durationScore * 0.20) +
            ($bounceScore * 0.20)
        );

        UxScore::create([
            'domain_id' => $this->domainId,
            'score' => $overall,
            'breakdown' => [
                'error_rate' => $errorScore,
                'rage_click_rate' => $rageScore,
                'form_abandon' => $formScore,
                'avg_session' => $durationScore,
                'bounce_rate' => $bounceScore,
            ],
            'calculated_at' => now(),
        ]);
    }
}
