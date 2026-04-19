<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(Plan::orderBy('sort_order')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPlanData($request);
        $plan = Plan::create($data);
        $this->auditLog($request, 'plan.create', $plan->id, [], $data);

        return $this->success($plan, 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(Plan::findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $data = $this->validatedPlanData($request, partial: true);
        $before = $plan->only(array_keys($data));
        $plan->update($data);
        $this->auditLog($request, 'plan.edit', $id, $before, $data);

        return $this->success($plan->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $this->auditLog($request, 'plan.delete', $id, $plan->toArray());
        $plan->delete();

        return $this->success(['message' => 'Plan deleted.']);
    }

    public function toggleVisibility(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $plan->update(['is_public' => !$plan->is_public]);

        return $this->success(['message' => 'Visibility toggled.', 'is_public' => $plan->is_public]);
    }

    private function validatedPlanData(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => ['string', 'max:100'],
            'slug' => ['string', 'max:60'],
            'description' => ['nullable', 'string'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string'],
            'price_monthly' => ['numeric', 'min:0'],
            'price_yearly' => ['numeric', 'min:0'],
            'features' => ['array'],
            'limits' => ['array'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];

        if (!$partial) {
            foreach ($rules as $key => $r) {
                if (!in_array('nullable', $r)) {
                    array_unshift($rules[$key], 'required');
                }
            }
        } else {
            foreach ($rules as $key => $r) {
                array_unshift($rules[$key], 'sometimes');
            }
        }

        return $request->validate($rules);
    }

    private function auditLog(Request $request, string $action, int $id, array $before = [], array $after = []): void
    {
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => $action,
            'target_type' => 'Plan',
            'target_id' => $id,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
