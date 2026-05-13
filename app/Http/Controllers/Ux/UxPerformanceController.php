<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UxPerformanceController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));

        // ── Page load timing ──────────────────────────────────────────────────
        $loadRows = $this->clickhouse->select("
            SELECT
                replaceRegexpOne(url, '^(https?://)www\\.', '\\\\1') AS url,
                round(avg(JSONExtractFloat(details, 'ttfb')))            AS avg_ttfb,
                round(avg(JSONExtractFloat(details, 'dom_interactive'))) AS avg_dom_interactive,
                round(avg(JSONExtractFloat(details, 'dom_complete')))    AS avg_dom_complete,
                round(avg(JSONExtractFloat(details, 'load_event')))      AS avg_load_event,
                round(avg(JSONExtractFloat(details, 'transfer_size')))   AS avg_transfer_size,
                count() AS samples
            FROM ux_events
            WHERE domain_id = {$domain->id}
              AND type = 'page_load'
              AND created_at >= '{$from} 00:00:00'
              AND created_at <= '{$to} 23:59:59'
            GROUP BY url
            ORDER BY avg_load_event DESC
            LIMIT 100
        ");

        // ── Slow resources ────────────────────────────────────────────────────
        $slowRows = $this->clickhouse->select("
            SELECT
                JSONExtractString(resource, 'name')         AS asset_url,
                JSONExtractString(resource, 'type')         AS asset_type,
                round(avg(JSONExtractFloat(resource, 'duration'))) AS avg_duration,
                round(avg(JSONExtractFloat(resource, 'size')))     AS avg_size,
                count() AS occurrences
            FROM (
                SELECT arrayJoin(
                    JSONExtractArrayRaw(details, 'resources')
                ) AS resource
                FROM ux_events
                WHERE domain_id = {$domain->id}
                  AND type = 'slow_resources'
                  AND created_at >= '{$from} 00:00:00'
                  AND created_at <= '{$to} 23:59:59'
            )
            GROUP BY asset_url, asset_type
            ORDER BY avg_duration DESC
            LIMIT 50
        ");

        // Attach rating to each page load row
        $pages = array_map(function (array $row) {
            $load = (int) $row['avg_load_event'];
            $rating = $load < 2500 ? 'good' : ($load < 4000 ? 'needs-improvement' : 'poor');
            return [
                'url' => $row['url'],
                'avg_ttfb' => (int) $row['avg_ttfb'],
                'avg_dom_interactive' => (int) $row['avg_dom_interactive'],
                'avg_dom_complete' => (int) $row['avg_dom_complete'],
                'avg_load_event' => (int) $row['avg_load_event'],
                'avg_transfer_size' => (int) $row['avg_transfer_size'],
                'samples' => (int) $row['samples'],
                'rating' => $rating,
            ];
        }, $loadRows);

        $slowAssets = array_map(function (array $row) {
            return [
                'url' => $row['asset_url'],
                'type' => $row['asset_type'] ?: 'other',
                'avg_duration' => (int) $row['avg_duration'],
                'avg_size' => (int) $row['avg_size'],
                'occurrences' => (int) $row['occurrences'],
            ];
        }, $slowRows);

        return $this->success([
            'pages' => $pages,
            'slow_assets' => $slowAssets,
        ]);
    }
}
