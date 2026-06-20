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

        // Playability gate: a recording is only listed once it has a valid
        // FullSnapshot (set at ingest). This guarantees we never surface broken/
        // incomplete recordings, and also hides legacy snapshot-less ones
        // (has_snapshot defaults false). The tracker only uploads sessions that
        // qualified (a friction signal or real engagement), so every listed
        // recording is both playable AND worth watching.
        $replays = SessionReplay::where('domain_id', $domain->id)
            ->where('status', '!=', 'pruned')
            ->where('has_snapshot', true)
            ->where('event_count', '>=', 5)
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

    /**
     * Return "notable" UX events for a session so the player can overlay markers
     * on the timeline (rage clicks, dead clicks, JS errors, etc.).
     *
     * Timestamps are server-side (ux_events.created_at) while the rrweb timeline
     * is client-side, so the player positions markers by their relative fraction
     * within this list's own time span — approximate, but good enough to jump to
     * the rough moment something went wrong.
     */
    public function markers(Request $request, int $domainId, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        SessionReplay::where('domain_id', $domain->id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        $safeSession = addslashes($sessionId);

        // Only friction / error signals — not every click/scroll (too noisy).
        $notable = "'rage_click','dead_click','js_error','excessive_scroll','quick_back','form_abandon','broken_link'";

        $rows = $this->clickhouse->select("
            SELECT
                type,
                url,
                element_selector,
                details,
                toUnixTimestamp(created_at) * 1000 AS ts_ms
            FROM ux_events
            WHERE domain_id = {$domain->id}
              AND session_id = '{$safeSession}'
              AND type IN ({$notable})
            ORDER BY created_at ASC, type ASC
            LIMIT 500
        ");

        return $this->success($rows);
    }

    /**
     * List replayable sessions that DROPPED at a given funnel step — i.e. reached
     * step N but never any later step. Powers the "watch drop-offs" link on the
     * funnels page. Returns the same shape as sessions().
     *
     * GET /api/replay/{domainId}/funnel-drops?pipeline_id=&step_order=&from=&to=
     */
    public function funnelDrops(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $pipelineId = (int) $request->query('pipeline_id', 0);
        $stepOrder = (int) $request->query('step_order', 0);
        if ($pipelineId <= 0) {
            return $this->error('pipeline_id is required.', 422);
        }

        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        // pipeline_events schema varies across deployments — detect columns first
        // (mirrors AnalyticsQueryService::pipelineFunnel).
        $cols = [];
        foreach ($this->clickhouse->select(
            "SELECT name FROM system.columns WHERE database = currentDatabase() AND table = 'pipeline_events'"
        ) as $c) {
            $name = (string) ($c['name'] ?? '');
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
        if (!isset($cols['step_order'])) {
            return $this->success([]); // no ordering column → can't compute per-step drop
        }
        $timeCol = isset($cols['ts']) ? 'ts' : (isset($cols['event_time']) ? 'event_time' : null);
        $timeFilter = $timeCol
            ? "AND {$timeCol} >= '{$from} 00:00:00' AND {$timeCol} < '{$to} 23:59:59'"
            : '';

        $sessionRows = $this->clickhouse->select("
            SELECT DISTINCT session_id
            FROM pipeline_events
            WHERE domain_id = {$domain->id}
              AND pipeline_id = {$pipelineId}
              AND step_order = {$stepOrder}
              {$timeFilter}
              AND session_id NOT IN (
                  SELECT session_id
                  FROM pipeline_events
                  WHERE domain_id = {$domain->id}
                    AND pipeline_id = {$pipelineId}
                    AND step_order > {$stepOrder}
                    {$timeFilter}
              )
            LIMIT 1000
        ");

        $sessionIds = array_values(array_filter(
            array_map(fn($r) => (string) ($r['session_id'] ?? ''), $sessionRows)
        ));
        if (empty($sessionIds)) {
            return $this->success([]);
        }

        // Only sessions that actually have a playable recording.
        $replays = SessionReplay::where('domain_id', $domain->id)
            ->whereIn('session_id', $sessionIds)
            ->where('status', '!=', 'pruned')
            ->where('event_count', '>=', 10)
            ->orderByDesc('recorded_at')
            ->limit(200)
            ->get();

        return $this->success($replays);
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
