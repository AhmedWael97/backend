<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ToolUsageLog;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /admin/marketing — acquisition-funnel view for the marketing team:
 * signup funnel conversion, referral performance, free-tool lead signals,
 * signups over time. Separate from AdminStatsController (revenue/plans) —
 * different audience, different question ("are we growing" vs "are we making money").
 */
class AdminMarketingController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(): JsonResponse
    {
        return $this->success([
            'funnel' => $this->funnel(),
            'signups_by_day' => $this->signupsByDay(),
            'referrals' => $this->referrals(),
            'tool_leads' => $this->toolLeads(),
        ]);
    }

    /** Registration funnel from EYE's own self-tracking (custom_events), last 30 days. */
    private function funnel(): array
    {
        $events = [
            'register_view', 'register_focus', 'register_submit', 'register_error',
            'register_complete', 'google_one_tap_shown', 'google_one_tap_credential',
        ];
        $inList = implode(',', array_map(fn ($e) => "'{$e}'", $events));

        $rows = [];
        try {
            $rows = $this->clickhouse->select("
                SELECT name, count() AS c, uniq(visitor_id) AS v
                FROM custom_events
                WHERE name IN ({$inList}) AND ts >= now() - INTERVAL 30 DAY
                GROUP BY name
            ");
        } catch (\Throwable $e) {
            report($e);
        }

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = ['count' => (int) $r['c'], 'unique' => (int) $r['v']];
        }

        $tracked = ($byName['register_complete']['unique'] ?? 0) + ($byName['google_one_tap_credential']['unique'] ?? 0);
        $actual = User::where('created_at', '>', now()->subDays(30))->count();

        return [
            'steps' => array_map(fn ($e) => ['name' => $e] + ($byName[$e] ?? ['count' => 0, 'unique' => 0]), $events),
            'tracked_signups_30d' => $tracked,
            'actual_signups_30d' => $actual,
        ];
    }

    private function signupsByDay(): array
    {
        return User::where('role', 'user')
            ->where('created_at', '>', now()->subDays(30))
            ->selectRaw("DATE(created_at) AS d, count(*) AS c")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => $r->d, 'count' => (int) $r->c]);
    }

    private function referrals(): array
    {
        $total = Referral::count();
        $rewarded = Referral::where('status', 'rewarded')->count();

        $topReferrers = Referral::select('referrer_user_id', DB::raw('count(*) as c'))
            ->groupBy('referrer_user_id')
            ->orderByDesc('c')
            ->limit(5)
            ->with('referrer:id,name,email')
            ->get()
            ->map(fn ($r) => ['name' => $r->referrer?->name, 'email' => $r->referrer?->email, 'count' => (int) $r->c]);

        return [
            'total' => $total,
            'rewarded' => $rewarded,
            'pending' => $total - $rewarded,
            'top_referrers' => $topReferrers,
        ];
    }

    /** Free-tool usage as a lead signal — someone checking their own domain = buying intent. */
    private function toolLeads(): array
    {
        $byTool = ToolUsageLog::where('created_at', '>', now()->subDays(30))
            ->select('tool', DB::raw('count(*) as c'), DB::raw('count(distinct checked_host) as hosts'))
            ->groupBy('tool')
            ->get();

        $recent = ToolUsageLog::where('created_at', '>', now()->subDays(30))
            ->whereNotNull('checked_host')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['tool', 'checked_host', 'score', 'user_id', 'created_at']);

        return [
            'by_tool' => $byTool,
            'recent' => $recent,
        ];
    }
}
