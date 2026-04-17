<?php

namespace App\Http\Controllers\Analytics;

use App\Models\Domain;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends BaseAnalyticsController
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function __invoke(Request $request, Domain $domain): JsonResponse
    {
        $this->ownedDomain($request, $domain);

        $active = $this->analytics->activeVisitors($domain->id);

        return response()->json([
            'active_visitors' => $active,
            'ts' => now()->toIso8601String(),
        ]);
    }
}
