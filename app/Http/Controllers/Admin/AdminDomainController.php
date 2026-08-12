<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDomainController extends Controller
{
    public function index(Request $request, ClickHouseService $ch): JsonResponse
    {
        $query = Domain::with('user')->latest();

        if ($search = $request->query('search')) {
            $query->where('domain', 'like', "%{$search}%")
                ->orWhereHas('user', fn($q) => $q->where('email', 'like', "%{$search}%"));
        }

        $paginator = $query->paginate(50);

        // events_30d was never populated -- the admin table always showed
        // blank/zero regardless of real traffic. Batch one ClickHouse query
        // for this page's domain IDs rather than N+1-ing per row.
        $ids = collect($paginator->items())->pluck('id')->implode(',');
        $counts = [];
        if ($ids !== '') {
            try {
                $rows = $ch->select("
                    SELECT domain_id, count() AS c
                    FROM events
                    WHERE domain_id IN ({$ids}) AND ts >= now() - INTERVAL 30 DAY
                    GROUP BY domain_id
                ");
                foreach ($rows as $row) {
                    $counts[(int) $row['domain_id']] = (int) $row['c'];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        foreach ($paginator->items() as $domain) {
            $domain->events_30d = $counts[$domain->id] ?? 0;
        }

        return $this->paginated($paginator);
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
