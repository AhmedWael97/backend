<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ImpersonationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::where('role', 'user')
            ->with(['subscription.plan']);

        if ($search = $request->query('search')) {
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($plan = $request->query('plan')) {
            $query->whereHas('subscription.plan', fn($q) => $q->where('slug', $plan));
        }

        $users = $query->latest()->paginate(50);

        return response()->json($users);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::with(['subscription.plan', 'domains'])->findOrFail($id);

        return response()->json(['data' => $user]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $id],
            'role' => ['sometimes', 'in:user,superadmin'],
            'status' => ['sometimes', 'in:active,blocked,suspended'],
        ]);

        $before = $user->only(array_keys($data));
        $user->update($data);

        $this->auditLog($request, 'user.edit', 'User', $id, $before, $data);

        return response()->json(['message' => 'User updated.', 'data' => $user->fresh()]);
    }

    public function block(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['status' => 'blocked']);
        $this->auditLog($request, 'user.block', 'User', $id);

        return response()->json(['message' => 'User blocked.']);
    }

    public function unblock(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['status' => 'active']);
        $this->auditLog($request, 'user.unblock', 'User', $id);

        return response()->json(['message' => 'User unblocked.']);
    }

    /**
     * POST /api/admin/users/{id}/impersonate
     * Returns a short-lived Sanctum token scoped to the target user.
     */
    public function impersonate(Request $request, int $id): JsonResponse
    {
        $target = User::where('role', '!=', 'superadmin')->findOrFail($id);

        ImpersonationLog::create([
            'admin_id' => $request->user()->id,
            'target_user_id' => $target->id,
            'started_at' => now(),
        ]);

        $this->auditLog($request, 'impersonate.start', 'User', $id);

        $token = $target->createToken('impersonation', ['*'], now()->addHours(2))->plainTextToken;

        return response()->json([
            'token' => $token,
            'target_user' => $target->only(['id', 'name', 'email']),
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ]);
    }

    /**
     * DELETE /api/admin/impersonate — end impersonation session.
     */
    public function endImpersonation(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        ImpersonationLog::where('target_user_id', $request->user()->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        return response()->json(['message' => 'Impersonation ended.']);
    }

    /**
     * POST /api/admin/users/{id}/disable-2fa
     */
    public function disable2fa(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['totp_secret' => null, 'totp_enabled' => false, 'totp_last_used_at' => null]);
        \App\Models\TotpBackupCode::where('user_id', $user->id)->delete();
        $this->auditLog($request, 'user.disable_2fa', 'User', $id);
        return response()->json(['message' => '2FA disabled for user.']);
    }

    /**
     * POST /api/admin/users/{id}/toggle-admin
     */
    public function toggleAdmin(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot modify your own admin role.'], 403);
        }
        $newRole = $user->role === 'superadmin' ? 'user' : 'superadmin';
        $user->update(['role' => $newRole]);
        $this->auditLog($request, 'user.toggle_admin', 'User', $id, ['role' => $user->role], ['role' => $newRole]);
        return response()->json(['message' => 'Role updated.', 'role' => $newRole]);
    }

    /**
     * DELETE /api/admin/users/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot delete your own account.'], 403);
        }
        $this->auditLog($request, 'user.delete', 'User', $id);
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['message' => 'User deleted.']);
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
