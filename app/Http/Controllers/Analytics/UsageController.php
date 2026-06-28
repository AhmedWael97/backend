<?php

namespace App\Http\Controllers\Analytics;

use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/analytics/{domainId}/usage
 *
 * Monthly event usage vs the domain owner's plan allowance. Events are always
 * stored (the tracker never rejects); this drives the in-app upsell: show the
 * plan's allowance and tease the rest ("N more events tracked — upgrade").
 */
class UsageController extends BaseAnalyticsController
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)->accessibleBy($user)->firstOrFail();

        // The view allowance comes from the DOMAIN OWNER's plan (the account that owns the data).
        $owner = $domain->user;
        $plan = $owner?->effectiveSubscription()?->plan ?? $owner?->activeSubscription?->plan;
        $limit = (int) (optional($plan)->getLimit('events_per_month', 10000) ?? 10000);
        // Super admins bypass plan caps entirely (consistent with the rest of the app);
        // and any plan with events_per_month = -1 (e.g. Business) is unlimited.
        $isAdminOwner = (bool) ($owner?->isSuperAdmin());
        $unlimited = $isAdminOwner || $limit === -1;

        $start = now()->startOfMonth()->format('Y-m-d H:i:s');
        $rows = $this->clickhouse->select(
            "SELECT count() AS c FROM events WHERE domain_id = {$domain->id} AND ts >= '{$start}'"
        );
        $tracked = (int) ($rows[0]['c'] ?? 0);
        $capped = !$unlimited && $tracked > $limit;

        return $this->success([
            'tracked_this_month' => $tracked,
            'limit' => $limit,
            'unlimited' => $unlimited,
            'capped' => $capped,
            'overage' => $capped ? max(0, $tracked - $limit) : 0,
            'plan' => $isAdminOwner ? 'Business' : ($plan?->name ?? 'Free'),
        ]);
    }
}
