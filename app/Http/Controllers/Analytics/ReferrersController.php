<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\Domain;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;

class ReferrersController extends BaseAnalyticsController
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function __invoke(AnalyticsRequest $request, Domain $domain): JsonResponse
    {
        $this->ownedDomain($request, $domain);

        $current = $this->analytics->topReferrers(
            $domain->id,
            $request->start(),
            $request->end(),
            $request->limit(),
        );

        if (!$request->compare()) {
            return $this->success($current);
        }

        $prev = $this->analytics->topReferrers(
            $domain->id,
            $request->prevStart(),
            $request->prevEnd(),
            $request->limit(),
        );

        return $this->success(['current' => $current, 'prev' => $prev]);
    }
}
