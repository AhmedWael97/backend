<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Jobs\ComputeUxScoreJob;
use App\Models\Domain;
use App\Models\UxScore;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxScoreController extends Controller
{
    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $latest = UxScore::where('domain_id', $domain->id)->latest('calculated_at')->first();
        $stale = !$latest || ($latest->calculated_at && $latest->calculated_at->lt(now()->subMinutes(15)));

        // Async refresh when data is stale — never block the HTTP request on a
        // multi-query ClickHouse aggregate. A Redis-backed cache key prevents a
        // thundering herd of refresh dispatches when many dashboards load.
        if ($stale) {
            $lockKey = "ux:score:refresh:{$domain->id}";
            if (\Illuminate\Support\Facades\Cache::add($lockKey, 1, now()->addMinutes(5))) {
                ComputeUxScoreJob::dispatch($domain->id)->onQueue('ai');
            }
        }

        if (!$latest) {
            return $this->success([
                'score' => null,
                'breakdown' => null,
                'calculated_at' => null,
                'is_stale' => true,
            ]);
        }

        return $this->success([
            'score' => $latest->score,
            'breakdown' => $latest->breakdown,
            'calculated_at' => $latest->calculated_at,
            'is_stale' => $stale,
        ]);
    }
}
