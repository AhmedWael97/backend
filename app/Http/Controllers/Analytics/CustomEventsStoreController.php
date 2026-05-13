<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomEventsStoreController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if ((int) $domain->user_id !== (int) $user->id && !$user->isSuperAdmin()) {
            abort(403);
        }

        // Require a real visitor + session attribution from the caller. Fabricating
        // UUIDs server-side made every server-recorded event look like a brand-new
        // visitor and inflated uniq(visitor_id) across the whole analytics layer.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'props' => ['nullable', 'array'],
            'url' => ['nullable', 'string', 'max:2048'],
            'session_id' => ['required', 'string', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i'],
            'visitor_id' => ['required', 'string', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i'],
        ]);

        $props = [];
        foreach (($data['props'] ?? []) as $k => $v) {
            $key = substr(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k), 0, 100);
            if ($key === '') {
                continue;
            }
            $props[$key] = is_scalar($v) ? substr((string) $v, 0, 200) : null;
        }

        $this->clickhouse->insertJson('custom_events', [
            [
                'domain_id' => $domain->id,
                'session_id' => strtolower($data['session_id']),
                'visitor_id' => strtolower($data['visitor_id']),
                'name' => trim((string) $data['name']),
                // Production schema stores props as String.
                'props' => json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'url' => (string) ($data['url'] ?? ''),
                'ts' => now()->format('Y-m-d H:i:s'),
            ]
        ]);

        return $this->success(['message' => 'Custom event saved.']);
    }
}
