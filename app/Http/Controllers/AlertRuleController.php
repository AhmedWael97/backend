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
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->success(AlertRule::where('domain_id', $domain->id)->get());
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string'],
            'metric' => ['sometimes', 'string'],
            'operator' => ['sometimes', 'string'],
            'threshold' => ['required'],
            'channel' => ['required', 'string'],
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
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($rule, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = AlertRule::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $data = $request->validate([
            'type' => ['sometimes', 'in:traffic_drop,error_spike,quota_warning,score_drop'],
            'threshold' => ['sometimes', 'array'],
            'channel' => ['sometimes', 'in:in_app,email,both'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule->update($data);

        return $this->success($rule);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        AlertRule::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id)
            ->delete();

        return $this->success(['message' => 'Alert rule deleted.']);
    }
}
