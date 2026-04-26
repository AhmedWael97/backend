<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomEventsStoreController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, Domain $domain): JsonResponse
    {
        if ((int) $domain->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'props' => ['nullable', 'array'],
            'url' => ['nullable', 'string', 'max:2048'],
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
                'session_id' => (string) Str::uuid(),
                'visitor_id' => (string) Str::uuid(),
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
