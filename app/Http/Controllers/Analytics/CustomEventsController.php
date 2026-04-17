<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\Domain;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;

class CustomEventsController extends BaseAnalyticsController
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function __invoke(AnalyticsRequest $request, Domain $domain): JsonResponse
    {
        $this->ownedDomain($request, $domain);

        $events = $this->analytics->customEvents(
            $domain->id,
            $request->start(),
            $request->end(),
            $request->limit(),
        );

        return response()->json(['data' => $events]);
    }
}
