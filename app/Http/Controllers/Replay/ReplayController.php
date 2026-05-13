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

    /**
     * List sessions that have a *playable* replay recording.
     *
     * A recording is only surfaced when it actually has enough events to be
     * meaningful — recordings still in `recording` state, with no events, or
     * with so few events that no FullSnapshot ever made it through, would
     * appear as broken videos in the UI. We filter them out here so the user
     * never sees a thumbnail that fails to play.
     */
    public function sessions(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $from = $request->query('from', now()->subDays(7)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        // Minimum event count for a recording to be considered viewable.
        // A valid rrweb session needs at least one Meta (type 4) + one
        // FullSnapshot (type 2) event plus a few IncrementalSnapshots to be
        // worth showing. 10 is a safe lower bound observed in production.
        $minEvents = 10;

        // No "status = complete" filter — nothing in the system flips that flag
        // today, so requiring it would hide every legitimate recording.
        // We rely on event_count + recorded_at recency as the playability gate.
        $replays = SessionReplay::where('domain_id', $domain->id)
            ->where('status', '!=', 'pruned')
            ->where('event_count', '>=', $minEvents)
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
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        // Verify the recording belongs to this domain (403 if not found).
        SessionReplay::where('domain_id', $domain->id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        $safeSession = addslashes($sessionId);

        $rows = $this->clickhouse->select("
            SELECT
                rrweb_type AS type,
                data,
                if(ts_ms > 0, ts_ms, toUnixTimestamp(timestamp) * 1000) AS timestamp
            FROM replay_events
            WHERE domain_id = {$domain->id}
              AND session_id = '{$safeSession}'
            ORDER BY if(ts_ms > 0, ts_ms, toUnixTimestamp(timestamp) * 1000) ASC, event_index ASC
            LIMIT 10000
        ");

        // Decode the stored JSON data field and extract event structure.
        //
        // Two storage formats exist in the DB:
        //
        //  NEW (current ingest): data column = {"type":N,"data":{...},"timestamp":N}
        //    → $fullEvent has a 'type' key; extract $fullEvent['data']
        //
        //  OLD (pre-fix ingest): data column = {the raw rrweb data object}
        //    e.g. FullSnapshot   → {"node":{...},"initialOffset":{...}}
        //    e.g. IncrementalSnapshot → {"source":1,"positions":[...]}
        //    e.g. Meta           → {"href":"...","width":1920,"height":1080}
        //    → $fullEvent has NO 'type' key; use $fullEvent directly as the data payload
        //
        $events = array_map(function (array $row) {
            $fullEvent = json_decode((string) ($row['data'] ?? '{}'), true) ?? [];

            if (isset($fullEvent['type'])) {
                // New format: extract the nested data field.
                $eventData = $fullEvent['data'] ?? [];
                $type = (int) $fullEvent['type'];
                $tsMs = (int) ($fullEvent['timestamp'] ?? $row['timestamp'] ?? 0);
            } else {
                // Old format: the entire stored object IS the data payload.
                // The type and timestamp come from the separate ClickHouse columns.
                $eventData = $fullEvent;
                $type = (int) ($row['type'] ?? 0);
                $tsMs = (int) ($row['timestamp'] ?? 0);
            }

            return [
                'type' => $type,
                'data' => $eventData,
                'timestamp' => $tsMs,
            ];
        }, $rows);

        return $this->success($events);
    }

    /** Delete a replay recording (GDPR / manual cleanup). */
    public function destroy(Request $request, int $domainId, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
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
