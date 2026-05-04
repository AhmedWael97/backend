<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

class AnalyticsQueryService
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Stats: summary + timeseries
    |--------------------------------------------------------------------------
    */
    public function stats(int $domainId, Carbon $start, Carbon $end, string $granularity): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        // Summary from events table
        $summaryRows = $this->clickhouse->select("
            SELECT
                countIf(type = 'pageview')       AS pageviews,
                uniq(visitor_id)                  AS unique_visitors,
                uniq(session_id)                  AS sessions,
                avgIf(duration, duration > 0)     AS avg_duration
            FROM events
            WHERE domain_id = {$domainId}
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
        ");

        $summary = $summaryRows[0] ?? [
            'pageviews' => 0,
            'unique_visitors' => 0,
            'sessions' => 0,
            'avg_duration' => 0,
        ];

        // Bounce rate from events — count sessions with exactly 1 pageview.
        // Derived at query time so it is never stale (avoids sessions table mutations).
        $bounceRows = $this->clickhouse->select("
            SELECT
                countIf(pv_count = 1) AS bounced,
                count()               AS total
            FROM (
                SELECT session_id, countIf(type = 'pageview') AS pv_count
                FROM events
                WHERE domain_id = {$domainId}
                  AND ts >= '{$startStr}'
                  AND ts < '{$endStr}'
                GROUP BY session_id
            )
        ");

        $brRow = $bounceRows[0] ?? ['bounced' => 0, 'total' => 0];
        $bounceRate = $brRow['total'] > 0
            ? round((int) $brRow['bounced'] / (int) $brRow['total'] * 100, 1)
            : 0.0;

        $summary['bounce_rate'] = $bounceRate;
        $summary['pageviews'] = (int) ($summary['pageviews'] ?? 0);
        $summary['unique_visitors'] = (int) ($summary['unique_visitors'] ?? 0);
        $summary['sessions'] = (int) ($summary['sessions'] ?? 0);
        $summary['avg_duration'] = (int) round((float) ($summary['avg_duration'] ?? 0));

        // Timeseries
        $dateExpr = $this->granularityExpr($granularity, 'ts');
        $timeseries = $this->clickhouse->select("
            SELECT
                {$dateExpr} AS period,
                countIf(type = 'pageview') AS pageviews,
                uniq(visitor_id)            AS unique_visitors,
                uniq(session_id)            AS sessions
            FROM events
            WHERE domain_id = {$domainId}
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
            GROUP BY period
            ORDER BY period ASC
        ");

        return [
            'summary' => $summary,
            'timeseries' => array_map(fn($r) => [
                'period' => $r['period'],
                'pageviews' => (int) $r['pageviews'],
                'unique_visitors' => (int) $r['unique_visitors'],
                'sessions' => (int) $r['sessions'],
            ], $timeseries),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Top pages
    |--------------------------------------------------------------------------
    */
    public function topPages(int $domainId, Carbon $start, Carbon $end, int $limit = 10): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $rows = $this->clickhouse->select("
            SELECT
                url,
                count()              AS pageviews,
                uniq(visitor_id)     AS unique_visitors,
                avgIf(duration, duration > 0) AS avg_duration
            FROM events
            WHERE domain_id = {$domainId}
              AND type = 'pageview'
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
            GROUP BY url
            ORDER BY pageviews DESC
            LIMIT {$limit}
        ");

        return array_map(fn($r) => [
            'url' => $r['url'],
            'pageviews' => (int) $r['pageviews'],
            'unique_visitors' => (int) $r['unique_visitors'],
            'avg_duration' => (int) round((float) $r['avg_duration']),
        ], $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Top referrers
    |--------------------------------------------------------------------------
    */
    public function topReferrers(int $domainId, Carbon $start, Carbon $end, int $limit = 10): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $rows = $this->clickhouse->select("
            SELECT
                referrer,
                count()          AS visits,
                uniq(visitor_id) AS unique_visitors
            FROM events
            WHERE domain_id = {$domainId}
              AND type = 'pageview'
              AND referrer != ''
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
            GROUP BY referrer
            ORDER BY visits DESC
            LIMIT {$limit}
        ");

        return array_map(fn($r) => [
            'referrer' => $r['referrer'],
            'visits' => (int) $r['visits'],
            'unique_visitors' => (int) $r['unique_visitors'],
        ], $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Devices breakdown (browser / OS / device_type)
    |--------------------------------------------------------------------------
    */
    public function devices(int $domainId, Carbon $start, Carbon $end): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $base = "
            FROM events
            WHERE domain_id = {$domainId}
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
        ";

        $browsers = $this->clickhouse->select("
            SELECT browser, count() AS visits, uniq(visitor_id) AS unique_visitors
            {$base}
            GROUP BY browser ORDER BY visits DESC
        ");

        $os = $this->clickhouse->select("
            SELECT os, count() AS visits, uniq(visitor_id) AS unique_visitors
            {$base}
            GROUP BY os ORDER BY visits DESC
        ");

        $devices = $this->clickhouse->select("
            SELECT device_type, count() AS visits, uniq(visitor_id) AS unique_visitors
            {$base}
            GROUP BY device_type ORDER BY visits DESC
        ");

        return [
            'browsers' => array_map(fn($r) => ['browser' => $r['browser'], 'visits' => (int) $r['visits'], 'unique_visitors' => (int) $r['unique_visitors']], $browsers),
            'os' => array_map(fn($r) => ['os' => $r['os'], 'visits' => (int) $r['visits'], 'unique_visitors' => (int) $r['unique_visitors']], $os),
            'devices' => array_map(fn($r) => ['device_type' => $r['device_type'], 'visits' => (int) $r['visits'], 'unique_visitors' => (int) $r['unique_visitors']], $devices),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Geographic breakdown
    |--------------------------------------------------------------------------
    */
    public function geo(int $domainId, Carbon $start, Carbon $end): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $base = "
            FROM events
            WHERE domain_id = {$domainId}
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
        ";

        $countries = $this->clickhouse->select("
            SELECT
                if(country = '', 'Unknown', country) AS country,
                count() AS pageviews,
                uniq(visitor_id) AS unique_visitors
            {$base}
            GROUP BY country ORDER BY pageviews DESC LIMIT 50
        ");

        $regions = $this->clickhouse->select("
            SELECT
                if(region = '', 'Unknown', region) AS region,
                if(country = '', 'Unknown', country) AS country,
                count() AS pageviews,
                uniq(visitor_id) AS unique_visitors
            {$base}
            GROUP BY region, country ORDER BY pageviews DESC LIMIT 50
        ");

        return [
            'countries' => array_map(fn($r) => ['country' => $r['country'], 'pageviews' => (int) $r['pageviews'], 'unique_visitors' => (int) $r['unique_visitors']], $countries),
            'regions' => array_map(fn($r) => ['region' => $r['region'], 'country' => $r['country'], 'pageviews' => (int) $r['pageviews'], 'unique_visitors' => (int) $r['unique_visitors']], $regions),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Custom events
    |--------------------------------------------------------------------------
    */
    public function customEvents(int $domainId, Carbon $start, Carbon $end, int $limit = 20): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $rows = $this->clickhouse->select("
            SELECT
                name,
                count()          AS occurrences,
                uniq(visitor_id) AS unique_visitors
            FROM custom_events
            WHERE domain_id = {$domainId}
              AND ts >= '{$startStr}'
              AND ts < '{$endStr}'
            GROUP BY name
            ORDER BY occurrences DESC
            LIMIT {$limit}
        ");

        return array_map(fn($r) => [
            'name' => $r['name'],
            'occurrences' => (int) $r['occurrences'],
            'unique_visitors' => (int) $r['unique_visitors'],
        ], $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline funnel
    |--------------------------------------------------------------------------
    */
    public function pipelineFunnel(int $domainId, int $pipelineId, Carbon $start, Carbon $end): array
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');
        $columnRows = $this->clickhouse->select("
            SELECT name
            FROM system.columns
            WHERE database = currentDatabase()
              AND table = 'pipeline_events'
        ");
        $columns = [];
        foreach ($columnRows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        $hasStepOrder = isset($columns['step_order']);
        $hasVisitorId = isset($columns['visitor_id']);
        $timeColumn = isset($columns['ts']) ? 'ts' : (isset($columns['event_time']) ? 'event_time' : null);
        if ($timeColumn === null) {
            return [];
        }
        $selectStepOrder = $hasStepOrder ? 'step_order' : 'toUInt32(0) AS step_order';
        $visitorExpr = $hasVisitorId ? 'uniq(visitor_id)' : 'uniq(session_id)';
        $groupBy = $hasStepOrder ? 'step_order, step_id' : 'step_id';
        $orderBy = $hasStepOrder ? 'step_order ASC' : 'sessions DESC';

        $rows = $this->clickhouse->select("
            SELECT
                {$selectStepOrder},
                step_id,
                uniq(session_id) AS sessions,
                {$visitorExpr} AS visitors
            FROM pipeline_events
            WHERE domain_id = {$domainId}
              AND pipeline_id = {$pipelineId}
              AND {$timeColumn} >= '{$startStr}'
              AND {$timeColumn} < '{$endStr}'
            GROUP BY {$groupBy}
            ORDER BY {$orderBy}
        ");

        // Compute drop-off and conversion rates
        $first = (int) ($rows[0]['sessions'] ?? 0);
        $result = [];
        foreach ($rows as $r) {
            $sessions = (int) $r['sessions'];
            $prevSessions = $result ? (int) end($result)['sessions'] : $sessions;
            $result[] = [
                'step_order' => (int) $r['step_order'],
                'step_id' => (int) $r['step_id'],
                'sessions' => $sessions,
                'visitors' => (int) $r['visitors'],
                'conversion_rate' => $first > 0 ? round($sessions / $first * 100, 1) : 0.0,
                'drop_off_rate' => $prevSessions > 0 ? round((1 - $sessions / $prevSessions) * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Active visitors (realtime — Redis sorted set)
    |--------------------------------------------------------------------------
    */
    public function activeVisitors(int $domainId): int
    {
        $key = "eye:realtime:{$domainId}";
        $cutoff = now()->subMinutes(5)->timestamp;

        Redis::zremrangebyscore($key, '-inf', (string) $cutoff);

        return (int) Redis::zcard($key);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    private function granularityExpr(string $granularity, string $column): string
    {
        return match ($granularity) {
            'hour' => "toStartOfHour({$column})",
            'week' => "toMonday({$column})",
            'month' => "toStartOfMonth({$column})",
            default => "toDate({$column})",
        };
    }
}
