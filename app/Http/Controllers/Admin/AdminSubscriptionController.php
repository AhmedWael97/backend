<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::with(['user', 'plan', 'paymentMethod']);

        if ($status = $request->query('status'))
            $query->where('status', $status);
        if ($plan = $request->query('plan'))
            $query->whereHas('plan', fn($q) => $q->where('slug', $plan));
        if ($search = $request->query('search')) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(50));
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(Subscription::with(['user', 'plan'])->findOrFail($id));
    }

    public function upgrade(Request $request, int $id): JsonResponse
    {
        $sub = Subscription::findOrFail($id);
        $data = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id']]);

        $before = ['plan_id' => $sub->plan_id, 'status' => $sub->status];
        $sub->update(['plan_id' => $data['plan_id'], 'status' => 'active']);
        $this->auditLog($request, 'subscription.change', 'Subscription', $id, $before, $data);

        return $this->success(['message' => 'Subscription upgraded.', 'data' => $sub->fresh()]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $sub = Subscription::findOrFail($id);
        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->auditLog($request, 'subscription.cancel', 'Subscription', $id);

        return $this->success(['message' => 'Subscription cancelled.']);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $sub = Subscription::findOrFail($id);
        $sub->update(['status' => 'paused']);

        return $this->success(['message' => 'Subscription paused.']);
    }

    public function resume(Request $request, int $id): JsonResponse
    {
        $sub = Subscription::findOrFail($id);
        $sub->update(['status' => 'active']);

        return $this->success(['message' => 'Subscription resumed.']);
    }

    /**
     * POST /api/admin/users/{id}/subscriptions — manually assign plan.
     */
    public function assign(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'payment_method_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        // Cancel existing active subscription
        Subscription::where('user_id', $user->id)->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $data['plan_id'],
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->auditLog($request, 'subscription.change', 'Subscription', $sub->id, [], $data);

        return $this->success(['message' => 'Plan assigned.', 'data' => $sub], 201);
    }

    private function auditLog(Request $request, string $action, string $type, int $id, array $before = [], array $after = []): void
    {
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => $action,
            'target_type' => $type,
            'target_id' => $id,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
