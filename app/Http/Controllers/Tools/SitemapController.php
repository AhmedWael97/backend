<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSitemapJob;
use App\Models\Domain;
use App\Models\SitemapJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * POST   /tools/sitemap/generate           — start a new sitemap job
 * GET    /tools/sitemap/history            — list user's past jobs
 * GET    /tools/sitemap/{job}              — poll job status
 * GET    /tools/sitemap/{job}/download     — download XML / JSON / CSV
 */
class SitemapController extends Controller
{
    private const FREE_LIMIT = 50;
    private const PAID_LIMIT = 200;

    // ── Generate ─────────────────────────────────────────────────────────────

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:' . self::PAID_LIMIT],
            'date_range_days' => ['nullable', 'integer', 'in:30,60,90'],
            'include_zero_traffic' => ['nullable', 'boolean'],
            'include_analytics_only' => ['nullable', 'boolean'],
            'domain_id' => ['nullable', 'integer', 'exists:domains,id'],
        ]);

        $url = rtrim($request->input('url'), '/');
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->error('Only http/https URLs are allowed.', 422);
        }

        $user = $request->user();
        $isPaid = $user->subscription?->plan?->name !== 'free'
            && $user->subscription !== null;
        $pageLimit = $isPaid ? self::PAID_LIMIT : self::FREE_LIMIT;

        $maxPages = (int) ($request->input('max_pages', $isPaid ? 100 : 50));
        $maxPages = min($maxPages, $pageLimit);

        // If domain_id provided, ensure user owns it (super admin bypasses)
        $domainId = null;
        if ($request->filled('domain_id')) {
            $domain = Domain::where('id', $request->input('domain_id'))
                ->accessibleBy($user)
                ->first();

            if (!$domain) {
                return $this->error('Domain not found or access denied.', 403);
            }
            $domainId = $domain->id;
        } else {
            // Auto-detect: check if start URL host matches any of user's domains
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            $matchedDomain = Domain::accessibleBy($user)
                ->where('domain', $host)
                ->first();
            $domainId = $matchedDomain?->id;
        }

        $sitemapJob = SitemapJob::create([
            'user_id' => $user->id,
            'domain_id' => $domainId,
            'start_url' => $url,
            'status' => 'pending',
            'config' => [
                'max_pages' => $maxPages,
                'date_range_days' => (int) $request->input('date_range_days', 90),
                'include_zero_traffic' => (bool) $request->input('include_zero_traffic', true),
                'include_analytics_only' => (bool) $request->input('include_analytics_only', true),
            ],
        ]);

        GenerateSitemapJob::dispatch($sitemapJob->id);

        return $this->success([
            'job_id' => $sitemapJob->id,
            'status' => 'pending',
            'max_pages' => $maxPages,
            'analytics_mode' => $domainId !== null,
        ], 202);
    }

    // ── Status (polling) ─────────────────────────────────────────────────────

    public function status(Request $request, SitemapJob $job): JsonResponse
    {
        $user = $request->user();

        if ($job->user_id !== $user->id && !$user->isSuperAdmin()) {
            return $this->error('Not found.', 404);
        }

        $payload = [
            'id' => $job->id,
            'status' => $job->status,
            'pages_crawled' => $job->pages_crawled,
            'start_url' => $job->start_url,
            'domain_id' => $job->domain_id,
            'analytics_mode' => $job->domain_id !== null,
            'created_at' => $job->created_at,
            'completed_at' => $job->completed_at,
            'error_message' => $job->error_message,
        ];

        if ($job->status === 'completed') {
            $result = $job->sitemap_result ?? [];
            $analytics = $job->analytics_result ?? [];

            $trafficLabels = array_count_values(array_column($analytics ?: $result, 'traffic_label'));

            $payload['summary'] = [
                'total_urls' => count($result),
                'high_traffic' => $trafficLabels['high_traffic'] ?? 0,
                'medium_traffic' => $trafficLabels['medium_traffic'] ?? 0,
                'low_traffic' => $trafficLabels['low_traffic'] ?? 0,
                'zero_traffic' => $trafficLabels['zero_traffic'] ?? 0,
                'analytics_only' => $trafficLabels['analytics_only'] ?? 0,
                'crawl_only' => $trafficLabels['crawl_only'] ?? 0,
            ];
            $payload['ai_analysis'] = $job->ai_analysis;
            $payload['sitemap_result'] = $result;
        }

        return $this->success($payload);
    }

    // ── Download ─────────────────────────────────────────────────────────────

    public function download(Request $request, SitemapJob $job): mixed
    {
        $user = $request->user();

        if ($job->user_id !== $user->id && !$user->isSuperAdmin()) {
            return $this->error('Not found.', 404);
        }

        if ($job->status !== 'completed') {
            return $this->error('Sitemap not ready yet.', 422);
        }

        $format = $request->input('format', 'xml');

        return match ($format) {
            'xml' => response($job->sitemap_xml ?? '', 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="sitemap.xml"',
            ]),
            'json' => response()->json($job->sitemap_result ?? [], 200, [
                'Content-Disposition' => 'attachment; filename="sitemap.json"',
            ]),
            'csv' => $this->downloadCsv($job),
            default => $this->error('Invalid format. Use xml, json, or csv.', 422),
        };
    }

    private function downloadCsv(SitemapJob $job): Response
    {
        $rows = $job->sitemap_result ?? [];
        $lines = ["URL,Priority,Changefreq,Lastmod,Traffic Label,Pageviews,Unique Visitors,Avg Click Depth"];

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['url']) . '"',
                $r['priority'] ?? '',
                $r['changefreq'] ?? '',
                $r['lastmod'] ?? '',
                $r['traffic_label'] ?? '',
                $r['pageviews'] ?? 0,
                $r['unique_visitors'] ?? 0,
                $r['avg_depth'] ?? '',
            ]);
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sitemap.csv"',
        ]);
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $jobs = SitemapJob::when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'start_url', 'status', 'pages_crawled', 'domain_id', 'created_at', 'completed_at', 'error_message', 'config']);

        return $this->success(['jobs' => $jobs]);
    }
}
