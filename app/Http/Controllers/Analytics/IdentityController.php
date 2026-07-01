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

        $identity = VisitorIdentity::where('domain_id', $domain->id)
            ->where('external_id', $externalId)
            ->firstOrFail();

        return $this->success($identity);
    }
}
