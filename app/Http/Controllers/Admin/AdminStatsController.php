<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStatsController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(): JsonResponse
    {
        $totalUsers = User::where('role', 'user')->count();
        $activeUsers = User::where('role', 'user')->where('status', 'active')->count();
        $activeSubs = Subscription::where('status', 'active')->count();

        // MRR: sum of active monthly subscription plan prices
        $mrr = Subscription::where('status', 'active')
            ->with('plan')
            ->get()
            ->sum(fn($sub) => (float) ($sub->plan?->price_monthly ?? 0));

        $topPlans = Plan::withCount(['subscriptions' => fn($q) => $q->where('status', 'active')])
            ->orderByDesc('subscriptions_count')
            ->limit(5)
            ->get(['id', 'name', 'subscriptions_count']);

        // Today's ingested events (approximate from ClickHouse)
        $today = now()->format('Y-m-d');
        $events = 0;
        try {
            $row = $this->clickhouse->select("SELECT count() AS c FROM events WHERE ts >= '{$today} 00:00:00'");
            $events = (int) ($row[0]['c'] ?? 0);
        } catch (\Throwable) {
        }

        return $this->success([
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'active_subscriptions' => $activeSubs,
            'mrr' => round($mrr, 2),
            'events_today' => $events,
            'top_plans' => $topPlans,
        ]);
    }
}
