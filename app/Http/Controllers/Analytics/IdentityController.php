<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\VisitorIdentity;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function index(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $search = $request->query('search');
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;

        // Group by person (external_id) rather than per device, so the same
        // person on multiple browsers/sessions shows as ONE row with a device
        // count, latest traits, and first/last seen.
        $base = VisitorIdentity::where('domain_id', $domain->id);
        if ($search) {
            $base->where(function ($q) use ($search) {
                $q->where('external_id', 'like', "%{$search}%")
                    ->orWhereRaw("traits::text ILIKE ?", ["%{$search}%"]);
            });
        }

        $total = (clone $base)->distinct()->count('external_id');

        $items = (clone $base)
            ->selectRaw(
                'external_id, '
                . 'count(*) as devices, '
                . 'min(first_identified_at) as first_identified_at, '
                . 'max(updated_at) as last_seen, '
                . '(array_agg(traits ORDER BY updated_at DESC))[1] as traits'
            )
            ->groupBy('external_id')
            ->orderByRaw('min(first_identified_at) DESC')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        // ── Enrich each person with ClickHouse data across ALL their devices:
        //    real last-seen, total distinct sessions, and country. ─────────────
        $externalIds = $items->pluck('external_id')->filter()->values();
        if ($externalIds->isNotEmpty()) {
            // external_id -> [visitor_id, ...] (a person can have several devices)
            $visitorMap = VisitorIdentity::where('domain_id', $domain->id)
                ->whereIn('external_id', $externalIds->all())
                ->get(['external_id', 'visitor_id'])
                ->groupBy('external_id')
                ->map(fn ($g) => $g->pluck('visitor_id')->all());

            $allVids = collect($visitorMap)->flatten()->unique()->values()->all();

            $ch = [];
            if (!empty($allVids)) {
                $inList = implode(',', array_map(
                    fn ($v) => "'" . str_replace("'", '', (string) $v) . "'",
                    $allVids
                ));
                $rows = $this->clickhouse->select(
                    'SELECT visitor_id, count(DISTINCT session_id) AS sessions, '
                    . 'toString(max(ts)) AS last_seen, anyLast(country) AS country '
                    . "FROM events WHERE domain_id = {$domain->id} AND visitor_id IN ({$inList}) "
                    . 'GROUP BY visitor_id'
                );
                foreach ($rows as $r) {
                    $ch[$r['visitor_id']] = $r;
                }
            }

            $items->transform(function ($item) use ($visitorMap, $ch) {
                $vids = $visitorMap[$item->external_id] ?? [];
                $sessions = 0;
                $lastSeen = null;
                $country = null;
                foreach ($vids as $vid) {
                    $d = $ch[$vid] ?? null;
                    if (!$d) {
                        continue;
                    }
                    $sessions += (int) ($d['sessions'] ?? 0);
                    $ls = $d['last_seen'] ?? null;
                    if ($ls && (!$lastSeen || $ls > $lastSeen)) {
                        $lastSeen = $ls;
                    }
                    if (!$country && !empty($d['country'])) {
                        $country = $d['country'];
                    }
                }
                $traits = is_array($item->traits) ? $item->traits : [];
                $item->name = $traits['name'] ?? null;
                $item->email = $traits['email'] ?? $item->external_id;
                $item->devices = (int) $item->devices;
                $item->sessions_count = $sessions;
                $item->last_seen = $lastSeen ?: $item->last_seen;
                $item->country = $country;
                return $item;
            });
        }

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => $items,
            'meta' => [
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
            ],
        ]);
    }

    public function show(Request $request, int $domainId, string $externalId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $records = VisitorIdentity::where('domain_id', $domain->id)
            ->where('external_id', $externalId)
            ->get();
        if ($records->isEmpty()) {
            abort(404);
        }

        $vids = $records->pluck('visitor_id')->filter()->unique()->values()->all();
        $latestTraits = (array) optional($records->sortByDesc('updated_at')->first())->traits;

        $sessions = [];
        $totalPageviews = 0;
        $totalTime = 0;

        if (!empty($vids)) {
            $inList = implode(',', array_map(
                fn ($v) => "'" . str_replace("'", '', (string) $v) . "'",
                $vids
            ));

            // Every page this person viewed (last 90 days), ordered within each session.
            $pages = $this->clickhouse->select(
                'SELECT session_id, url, title, toString(ts) AS ts, referrer, '
                . 'device_type AS device, browser, country '
                . 'FROM events '
                . "WHERE domain_id = {$domain->id} AND visitor_id IN ({$inList}) "
                . "AND type = 'pageview' AND ts > now() - INTERVAL 90 DAY "
                . 'ORDER BY session_id, ts LIMIT 3000'
            );

            // Time-on-page (max heartbeat duration per page) to fill the last page of
            // each session, where there's no following pageview to diff against.
            $tops = $this->clickhouse->select(
                'SELECT session_id, url, max(duration) AS secs '
                . 'FROM events '
                . "WHERE domain_id = {$domain->id} AND visitor_id IN ({$inList}) "
                . "AND type = 'time_on_page' AND ts > now() - INTERVAL 90 DAY "
                . 'GROUP BY session_id, url'
            );
            $dwellMap = [];
            foreach ($tops as $t) {
                $dwellMap[$t['session_id'] . '|' . $t['url']] = (int) ($t['secs'] ?? 0);
            }

            $bySession = [];
            foreach ($pages as $p) {
                $bySession[$p['session_id']][] = $p;
            }

            foreach ($bySession as $sid => $ps) {
                $pageList = [];
                $sessDur = 0;
                $count = count($ps);
                foreach ($ps as $i => $p) {
                    if ($i + 1 < $count) {
                        $dwell = strtotime($ps[$i + 1]['ts']) - strtotime($p['ts']);
                    } else {
                        $dwell = $dwellMap[$sid . '|' . $p['url']] ?? null;
                    }
                    $dwell = ($dwell !== null && $dwell >= 0 && $dwell < 3600) ? $dwell : null;
                    if ($dwell !== null) {
                        $sessDur += $dwell;
                    }
                    $pageList[] = [
                        'url' => $p['url'],
                        'title' => $p['title'] ?: null,
                        'ts' => $p['ts'],
                        'dwell_seconds' => $dwell,
                    ];
                }
                $first = $ps[0];
                $sessions[] = [
                    'session_id' => $sid,
                    'started_at' => $first['ts'],
                    'referrer' => $first['referrer'] ?: null,
                    'device' => $first['device'] ?: null,
                    'browser' => $first['browser'] ?: null,
                    'country' => $first['country'] ?: null,
                    'pageviews' => $count,
                    'duration_seconds' => $sessDur,
                    'pages' => $pageList,
                ];
                $totalPageviews += $count;
                $totalTime += $sessDur;
            }

            usort($sessions, fn ($a, $b) => strcmp($b['started_at'], $a['started_at']));
            $sessions = array_slice($sessions, 0, 50);
        }

        return $this->success([
            'identity' => [
                'external_id' => $externalId,
                'name' => $latestTraits['name'] ?? null,
                'email' => $latestTraits['email'] ?? $externalId,
                'traits' => $latestTraits,
                'devices' => $records->pluck('visitor_id')->unique()->count(),
                'first_identified_at' => optional($records->min('first_identified_at')),
            ],
            'stats' => [
                'sessions' => count($sessions),
                'pageviews' => $totalPageviews,
                'total_time_seconds' => $totalTime,
            ],
            'sessions' => $sessions,
        ]);
    }
}
