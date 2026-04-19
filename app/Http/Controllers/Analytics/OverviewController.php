<?php

namespace App\Http\Controllers\Analytics;

use App\Models\Domain;
use App\Services\AnalyticsQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends BaseAnalyticsController
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    /**
     * GET /api/analytics/{domainId}/overview?period=30d&compare=1
     * Returns a single, richly combined overview response consumed by the dashboard.
     */
    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        ['start' => $start, 'end' => $end] = $this->parsePeriod($request->query('period', '30d'));

        $stats = $this->analytics->stats($domain->id, $start, $end, 'day');
        $topPages = $this->analytics->topPages($domain->id, $start, $end, 10);
        $geo = $this->analytics->geo($domain->id, $start, $end);
        $devices = $this->analytics->devices($domain->id, $start, $end);
        $summary = $stats['summary'];
        $timeseries = $stats['timeseries'];

        $data = [
            'visitors' => $summary['unique_visitors'],
            'pageviews' => $summary['pageviews'],
            'sessions' => $summary['sessions'],
            'bounce_rate' => $summary['bounce_rate'],
            'avg_session_duration' => $summary['avg_duration'],
            'top_pages' => array_map(fn($p) => ['url' => $p['url'], 'views' => $p['pageviews']], $topPages),
            'top_countries' => array_map(fn($c) => ['country' => $c['country'], 'count' => $c['pageviews']], $geo['countries']),
            'top_devices' => array_map(fn($d) => ['device' => $d['device_type'], 'count' => $d['visits']], $devices['devices']),
            'chart_data' => array_map(fn($t) => [
                'date' => $t['period'],
                'visitors' => $t['unique_visitors'],
                'pageviews' => $t['pageviews'],
            ], $timeseries),
        ];

        // Optional comparison period
        if ($request->query('compare') === '1' || $request->query('compare') === 'true') {
            $diff = $start->diffInSeconds($end);
            $prevEnd = (clone $start)->subSecond();
            $prevStart = (clone $prevEnd)->subSeconds($diff);

            $prevStats = $this->analytics->stats($domain->id, $prevStart, $prevEnd, 'day');
            $ps = $prevStats['summary'];
            $data['compare'] = [
                'visitors' => $ps['unique_visitors'],
                'pageviews' => $ps['pageviews'],
                'sessions' => $ps['sessions'],
                'bounce_rate' => $ps['bounce_rate'],
                'avg_session_duration' => $ps['avg_duration'],
                'top_pages' => [],
                'top_countries' => [],
                'top_devices' => [],
                'chart_data' => array_map(fn($t) => [
                    'date' => $t['period'],
                    'visitors' => $t['unique_visitors'],
                    'pageviews' => $t['pageviews'],
                ], $prevStats['timeseries']),
            ];
        }

        return $this->success($data);
    }

    /**
     * Parse a period string like "7d", "30d", "90d" into start/end Carbon dates.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function parsePeriod(string $period): array
    {
        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        return [
            'start' => now()->subDays($days)->startOfDay(),
            'end' => now()->endOfDay(),
        ];
    }
}
