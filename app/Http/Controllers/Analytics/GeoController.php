<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\Domain;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;

class GeoController extends BaseAnalyticsController
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function __invoke(AnalyticsRequest $request, Domain $domain): JsonResponse
    {
        $this->ownedDomain($request, $domain);

        $data = $this->analytics->geo(
            $domain->id,
            $request->start(),
            $request->end(),
        );

        return $this->success($data);
    }
}
