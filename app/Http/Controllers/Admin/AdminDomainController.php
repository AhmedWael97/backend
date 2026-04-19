<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Domain::with('user')->latest();

        if ($search = $request->query('search')) {
            $query->where('domain', 'like', "%{$search}%")
                ->orWhereHas('user', fn($q) => $q->where('email', 'like', "%{$search}%"));
        }

        return $this->paginated($query->paginate(50));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $domain = Domain::findOrFail($id);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'domain.delete',
            'target_type' => 'Domain',
            'target_id' => $id,
            'before' => $domain->toArray(),
            'after' => null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $domain->delete();

        return $this->success(['message' => 'Domain deleted.']);
    }
}
