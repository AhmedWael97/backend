<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\Domain;
use App\Models\Pipeline;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;

class PipelineController extends BaseAnalyticsController
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function __invoke(AnalyticsRequest $request, Domain $domain, Pipeline $pipeline): JsonResponse
    {
        $this->ownedDomain($request, $domain);

        // Ensure pipeline belongs to this domain
        if ($pipeline->domain_id !== $domain->id) {
            abort(404);
        }

        $steps = $this->analytics->pipelineFunnel(
            $domain->id,
            $pipeline->id,
            $request->start(),
            $request->end(),
        );

        return $this->success($steps);
    }
}
