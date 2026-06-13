<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertRuleController extends Controller
{
    public function index(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        return $this->success(AlertRule::where('domain_id', $domain->id)->get());
    }

    /**
     * Apply a sensible set of default alert rules to EVERY domain the user owns
     * that doesn't already have a rule of that type. Saves setting them up 20×.
     *
     * POST /api/v1/alert-rules/apply-defaults  { channel?: string }
     */
    public function applyDefaults(Request $request): JsonResponse
    {
        $user = $request->user();
        $channel = (string) $request->input('channel', 'email');
        if (!in_array($channel, ['in_app', 'email', 'both'], true)) {
            $channel = 'email';
        }

        $domains = $user->isSuperAdmin()
            ? Domain::query()->get(['id'])
            : $user->domains()->get(['id']);

        $defaults = [
            ['type' => 'traffic_anomaly', 'threshold' => ['sensitivity' => 2.5]],
            ['type' => 'conversion_drop', 'threshold' => ['percent' => 30]],
            ['type' => 'error_spike', 'threshold' => ['percent' => 5]],
        ];

        $created = 0;
        foreach ($domains as $domain) {
            foreach ($defaults as $def) {
                $exists = AlertRule::where('domain_id', $domain->id)
                    ->where('type', $def['type'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                AlertRule::create([
                    'domain_id' => $domain->id,
                    'type' => $def['type'],
                    'threshold' => $def['threshold'],
                    'channel' => $channel,
                    'is_active' => true,
                ]);
                $created++;
            }
        }

        return $this->success(['created' => $created, 'domains' => $domains->count()]);
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string'],
            'metric' => ['sometimes', 'string'],
            'operator' => ['sometimes', 'string'],
            'threshold' => ['required'],
            'channel' => ['required', 'string'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:1024'],
            'is_active' => ['boolean'],
        ]);

        $rule = AlertRule::create([
            'domain_id' => $domain->id,
            'type' => $data['type'] ?? $data['metric'] ?? 'traffic_drop',
            'threshold' => is_array($data['threshold']) ? $data['threshold'] : [
                'operator' => $data['operator'] ?? '>',
                'value' => $data['threshold'],
                'name' => $data['name'] ?? null,
            ],
            'channel' => $data['channel'],
            'webhook_url' => $data['webhook_url'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($rule, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $rule = AlertRule::when(
                !$user->isSuperAdmin(),
                fn($q) => $q->whereHas('domain', fn($d) => $d->where('user_id', $user->id))
            )
            ->findOrFail($id);

        $data = $request->validate([
            'type' => ['sometimes', 'in:traffic_drop,traffic_anomaly,error_spike,quota_warning,conversion_drop,score_drop'],
            'threshold' => ['sometimes', 'array'],
            'channel' => ['sometimes', 'in:in_app,email,both,slack,discord'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:1024'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule->update($data);

        return $this->success($rule);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        AlertRule::when(
                !$user->isSuperAdmin(),
                fn($q) => $q->whereHas('domain', fn($d) => $d->where('user_id', $user->id))
            )
            ->findOrFail($id)
            ->delete();

        return $this->success(['message' => 'Alert rule deleted.']);
    }
}
