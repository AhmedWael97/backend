<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\VisitorIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $search = $request->query('search');
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;

        $query = VisitorIdentity::where('domain_id', $domain->id);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('external_id', 'like', "%{$search}%")
                    ->orWhereRaw("traits::text ILIKE ?", ["%{$search}%"]);
            });
        }

        $total = $query->count();
        $items = $query->latest('first_identified_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

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
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $identity = VisitorIdentity::where('domain_id', $domain->id)
            ->where('external_id', $externalId)
            ->firstOrFail();

        return $this->success($identity);
    }
}
