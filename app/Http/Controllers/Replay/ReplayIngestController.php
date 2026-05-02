<?php

namespace App\Http\Controllers\Replay;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SessionReplay;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * POST /api/track/replay
 *
 * Public endpoint — receives batched rrweb events from the eye-replay.js module.
 * Stores each event in the ClickHouse `replay_events` table and upserts a row in
 * the PostgreSQL `session_replays` table so the dashboard can list recordings.
 */
class ReplayIngestController extends Controller
{
    private const CORS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, X-Eye-Token',
    ];

    public function __invoke(Request $request, ClickHouseService $clickhouse): Response
    {
        $body = $request->json()->all();
        $token = $body['t'] ?? $body['token'] ?? $request->header('X-Eye-Token');

        if (!$token) {
            return response('', 400, self::CORS);
        }

        $domain = Domain::where(function ($q) use ($token) {
            $q->where('script_token', $token)
                ->orWhere('previous_script_token', $token);
        })->where('active', true)->first();

        if (!$domain) {
            return response('', 401, self::CORS);
        }

        $sessionId = $this->sanitizeUuid($body['sid'] ?? null);
        $visitorId = $this->sanitizeUuid($body['vid'] ?? null);
        $events = is_array($body['events'] ?? null) ? $body['events'] : [];

        if (empty($events)) {
            return response('', 204, self::CORS);
        }

        $rows = [];
        $startUrl = '';

        $counterKey = sprintf('eye:replay:idx:%d:%s', (int) $domain->id, $sessionId);

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $type = (int) ($event['type'] ?? 0);

            // rrweb timestamps are Unix milliseconds; convert to DateTime
            $timestamp = isset($event['timestamp'])
                ? date('Y-m-d H:i:s', (int) floor((int) $event['timestamp'] / 1000))
                : now()->format('Y-m-d H:i:s');

            // rrweb type 4 = Meta event — contains the page URL at recording start
            if ($type === 4 && !$startUrl && isset($event['data']['href'])) {
                $startUrl = substr((string) $event['data']['href'], 0, 2048);
            }

            $tsMsRaw = isset($event['timestamp']) ? (int) $event['timestamp'] : 0;

            // CRITICAL FIX: Store entire event structure, not just data field
            // The FullSnapshot event needs complete data including node tree
            $eventData = [
                'type' => $type,
                'data' => $event['data'] ?? [],
                'timestamp' => $event['timestamp'] ?? 0,
            ];

            $encodedData = json_encode($eventData, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encodedData === false) {
                $encodedData = '{"type":0,"data":{},"timestamp":0}';
            }

            // Maintain monotonic event ordering across all flush batches.
            $eventIndex = (int) Redis::incr($counterKey);

            $rows[] = [
                'domain_id' => (int) $domain->id,
                'session_id' => $sessionId,
                'event_index' => $eventIndex,
                'rrweb_type' => $type,
                'data' => $encodedData,
                'ts_ms' => $tsMsRaw,
                'timestamp' => $timestamp,
            ];
        }

        if (!empty($rows)) {
            $clickhouse->insertJson('replay_events', $rows);
        }

        // Upsert session_replays — increment event_count atomically
        $replay = SessionReplay::firstOrCreate(
            ['domain_id' => $domain->id, 'session_id' => $sessionId],
            [
                'visitor_id' => $visitorId,
                'start_url' => $startUrl ?: '',
                'event_count' => 0,
                'status' => 'recording',
                'recorded_at' => now(),
            ]
        );

        $replay->increment('event_count', count($rows));

        if ($startUrl && !$replay->start_url) {
            $replay->start_url = $startUrl;
        }

        $replay->status = 'recording';
        $replay->recorded_at = now();
        $replay->save();

        return response('', 204, self::CORS);
    }

    private function sanitizeUuid(mixed $value): string
    {
        $str = (string) ($value ?? '');
        return preg_match('/^[a-f0-9\-]{8,64}$/i', $str)
            ? $str
            : Str::uuid()->toString();
    }
}
