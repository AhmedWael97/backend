<?php

namespace App\Http\Controllers\Replay;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SessionReplay;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated routes — manage and stream session replay data.
 *
 * GET    /api/replay/{domainId}/sessions            — list recordings
 * GET    /api/replay/{domainId}/sessions/{sessionId} — stream events
 * DELETE /api/replay/{domainId}/sessions/{sessionId} — GDPR delete
 */
class ReplayController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    /** List sessions that have a replay recording. */
    public function sessions(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $from = $request->query('from', now()->subDays(7)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        $replays = SessionReplay::where('domain_id', $domain->id)
            ->whereBetween('recorded_at', [
                $from . ' 00:00:00',
                $to . ' 23:59:59',
            ])
            ->orderByDesc('recorded_at')
            ->limit(200)
            ->get();

        return $this->success($replays);
    }

    /** Return all rrweb events for a single session (max 10 000 rows). */
    public function events(Request $request, int $domainId, string $sessionId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Verify the recording belongs to this domain (403 if not found).
        SessionReplay::where('domain_id', $domain->id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        $safeSession = addslashes($sessionId);

        $rows = $this->clickhouse->select("
            SELECT type, data, toUnixTimestamp64Milli(ts) AS timestamp
            FROM replay_events
            WHERE domain_id = {$domain->id}
              AND session_id = '{$safeSession}'
            ORDER BY ts ASC
            LIMIT 10000
        ");

        // Decode the stored JSON data field so the frontend gets native objects.
        $events = array_map(function (array $row) {
            $row['data'] = json_decode((string) ($row['data'] ?? '{}'), true) ?? [];
            return $row;
        }, $rows);

        return $this->success($events);
    }

    /** Delete a replay recording (GDPR / manual cleanup). */
    public function destroy(Request $request, int $domainId, string $sessionId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $replay = SessionReplay::where('domain_id', $domain->id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        $safeSession = addslashes($sessionId);

        // Async ClickHouse mutation delete (eventually consistent).
        $this->clickhouse->execute("
            ALTER TABLE replay_events DELETE
            WHERE domain_id = {$domain->id}
              AND session_id = '{$safeSession}'
        ");

        $replay->update(['status' => 'pruned']);
        $replay->delete();

        return $this->success(null, 200);
    }
}
