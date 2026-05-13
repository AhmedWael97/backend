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

        // Sessions that touched a UX-flagged event (error / rage / dead-click /
        // form abandon). We score against SESSIONS, not raw events — otherwise a
        // single repeating JS error in a high-traffic site collapses the score
        // to zero because rate-per-event saturates at 0.1%.
        $sessionsAffected = $clickhouse->select("
            SELECT
                type,
                uniq(session_id) AS sessions
            FROM ux_events
            WHERE domain_id = {$id}
              AND created_at >= '{$from}' AND created_at < '{$to}'
              AND type IN ('js_error', 'rage_click', 'form_abandon')
            GROUP BY type
        ");
        $sessionsMap = collect($sessionsAffected)->pluck('sessions', 'type');

        // Total unique sessions in the period (denominator for the rates).
        $sessTotalRow = $clickhouse->select("
            SELECT
                uniq(session_id) AS total,
                avg(duration_seconds) AS avg_duration,
                round(toFloat64(countIf(page_count = 1)) / nullIf(count(), 0), 4) AS bounce_rate
            FROM sessions
            WHERE domain_id = {$id}
              AND started_at >= '{$from}' AND started_at < '{$to}'
        ");
        $totalSessions = max(1, (int) ($sessTotalRow[0]['total'] ?? 1));
        $avgDuration = (float) ($sessTotalRow[0]['avg_duration'] ?? 0);
        $bounceRate = (float) ($sessTotalRow[0]['bounce_rate'] ?? 0);

        // Per-session rates ∈ [0, 1].
        // Thresholds chosen so a typical "needs work" site (5-10% of sessions
        // hitting the bad signal) lands in the 50-70 score band, not zero.
        $errSessions  = (int) ($sessionsMap['js_error']     ?? 0);
        $rageSessions = (int) ($sessionsMap['rage_click']   ?? 0);
        $formSessions = (int) ($sessionsMap['form_abandon'] ?? 0);

        // Saturate each rate against a per-bucket "max bad" threshold:
        //   10% sessions with JS error  → errorScore = 0
        //   10% sessions with rage      → rageScore  = 0
        //   20% sessions with abandon   → formScore  = 0
        $errorRate   = min($errSessions  / $totalSessions / 0.10, 1);
        $rageRate    = min($rageSessions / $totalSessions / 0.10, 1);
        $formAbandon = min($formSessions / $totalSessions / 0.20, 1);
        $avgDurationSub = min($avgDuration / 180, 1); // 3 min = 100

        $errorScore    = (int) round((1 - $errorRate)    * 100);
        $rageScore     = (int) round((1 - $rageRate)     * 100);
        $formScore     = (int) round((1 - $formAbandon)  * 100);
        $durationScore = (int) round($avgDurationSub * 100);
        $bounceScore   = (int) round((1 - $bounceRate) * 100);

        $overall = (int) round(
            ($errorScore * 0.25) +
            ($rageScore * 0.20) +
            ($formScore * 0.15) +
            ($durationScore * 0.20) +
            ($bounceScore * 0.20)
        );

        // Upsert pattern: keep one row per domain rather than creating a new
        // one every refresh. updateOrCreate avoids unbounded growth of the
        // ux_scores table.
        UxScore::updateOrCreate(
            ['domain_id' => $this->domainId],
            [
                'score' => $overall,
                'breakdown' => [
                    'error_rate' => $errorScore,
                    'rage_click_rate' => $rageScore,
                    'form_abandon' => $formScore,
                    'avg_session' => $durationScore,
                    'bounce_rate' => $bounceScore,
                ],
                'calculated_at' => now(),
            ]
        );
    }
}
