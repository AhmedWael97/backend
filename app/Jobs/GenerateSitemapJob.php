<?php

namespace App\Jobs;

use App\Models\SitemapJob;
use App\Services\AnthropicService;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    private const UA = 'Mozilla/5.0 (compatible; EyeSitemapBot/1.0)';
    private const HTTP_TIMEOUT = 12;
    private const MAX_BYTES = 1_000_000; // 1 MB per page

    // Traffic weight → base priority
    private const PRIORITY_MAP = [
        'high_traffic' => 0.9,
        'medium_traffic' => 0.7,
        'low_traffic' => 0.5,
        'zero_traffic' => 0.3,
        'analytics_only' => 0.8,
        'crawl_only' => 0.5,   // no analytics available
    ];

    public function __construct(public readonly int $sitemapJobId)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function handle(ClickHouseService $clickhouse, AnthropicService $anthropic): void
    {
        /** @var SitemapJob $job */
        $job = SitemapJob::findOrFail($this->sitemapJobId);

        $config = $job->config ?? [];
        $maxPages = (int) ($config['max_pages'] ?? 100);
        $dateRangeDays = (int) ($config['date_range_days'] ?? 90);
        $includeZero = (bool) ($config['include_zero_traffic'] ?? true);
        $includeOnly = (bool) ($config['include_analytics_only'] ?? true);

        try {
            // ── Phase A: Crawl ────────────────────────────────────────────
            $job->update(['status' => 'crawling']);
            $crawlData = $this->crawl($job->start_url, $maxPages, $job);

            // ── Phase B: Analytics enrichment ────────────────────────────
            $analyticsData = [];
            if ($job->domain_id) {
                $job->update(['status' => 'enriching']);
                $analyticsData = $this->enrichAnalytics(
                    $job->domain_id,
                    $crawlData,
                    $dateRangeDays,
                    $clickhouse
                );
            }
            $job->update(['analytics_result' => $analyticsData]);

            // ── Phase C: Claude AI analysis ───────────────────────────────
            $job->update(['status' => 'analyzing']);
            $aiAnalysis = $this->analyzeWithAI($job->start_url, $crawlData, $analyticsData, $anthropic);
            $job->update(['ai_analysis' => $aiAnalysis]);

            // ── Phase D: Build final sitemap ──────────────────────────────
            $sitemapResult = $this->buildSitemap(
                $crawlData,
                $analyticsData,
                $aiAnalysis,
                $includeZero,
                $includeOnly
            );
            $xml = $this->generateXml($sitemapResult);

            $job->update([
                'status' => 'completed',
                'sitemap_result' => $sitemapResult,
                'sitemap_xml' => $xml,
                'completed_at' => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('GenerateSitemapJob failed', [
                'job_id' => $this->sitemapJobId,
                'message' => $e->getMessage(),
            ]);
            $job->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 1000),
                'completed_at' => now(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase A — BFS Crawl
    // ─────────────────────────────────────────────────────────────────────────

    private function crawl(string $startUrl, int $maxPages, SitemapJob $job): array
    {
        $startUrl = rtrim($startUrl, '/');
        $baseHost = strtolower(parse_url($startUrl, PHP_URL_HOST) ?? '');
        $scheme = strtolower(parse_url($startUrl, PHP_URL_SCHEME) ?? 'https');

        $visited = [];
        $queue = [$startUrl];
        $results = [];

        // Seed from robots.txt sitemap directives
        $this->seedFromRobots($startUrl, $scheme, $baseHost, $queue);

        $counter = 0;

        while (!empty($queue) && count($results) < $maxPages) {
            $url = array_shift($queue);
            $normalised = $this->normaliseUrl($url);

            if (isset($visited[$normalised]))
                continue;
            $visited[$normalised] = true;

            $urlHost = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            if ($urlHost !== $baseHost)
                continue;

            try {
                $response = Http::withHeaders(['User-Agent' => self::UA])
                    ->timeout(self::HTTP_TIMEOUT)
                    ->get($url);
            } catch (\Throwable) {
                continue;
            }

            $statusCode = $response->status();
            $html = substr($response->body(), 0, self::MAX_BYTES);
            $lastMod = $response->header('Last-Modified') ?? null;

            // Extract metadata
            $title = $this->extractTitle($html);
            $canonical = $this->extractCanonical($html, $url);

            $results[] = [
                'url' => $url,
                'status_code' => $statusCode,
                'depth' => $this->calculateDepth($url, $startUrl),
                'title' => $title,
                'canonical' => $canonical,
                'last_modified' => $lastMod ? date('Y-m-d', strtotime($lastMod)) : null,
            ];

            // Update live progress every 5 pages
            if (++$counter % 5 === 0) {
                $job->update(['pages_crawled' => count($results)]);
            }

            // Extract and queue internal links
            if ($response->successful()) {
                $links = $this->extractInternalLinks($html, $url, $baseHost, $scheme);
                foreach ($links as $link) {
                    $norm = $this->normaliseUrl($link);
                    if (!isset($visited[$norm])) {
                        $queue[] = $link;
                    }
                }
            }
        }

        $job->update([
            'crawl_result' => $results,
            'pages_crawled' => count($results),
        ]);

        return $results;
    }

    private function seedFromRobots(string $startUrl, string $scheme, string $baseHost, array &$queue): void
    {
        try {
            $robotsUrl = "{$scheme}://{$baseHost}/robots.txt";
            $resp = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(8)
                ->get($robotsUrl);

            if ($resp->successful()) {
                preg_match_all('/^Sitemap:\s*(.+)/im', $resp->body(), $matches);
                foreach ($matches[1] as $sitemapUrl) {
                    $sitemapUrl = trim($sitemapUrl);
                    $this->parseSitemapXml($sitemapUrl, $baseHost, $queue);
                }
            }
        } catch (\Throwable) {
            // Best-effort — ignore errors
        }

        // Also try root sitemap.xml directly
        try {
            $sitemapUrl = rtrim($startUrl, '/') . '/sitemap.xml';
            $this->parseSitemapXml($sitemapUrl, $baseHost, $queue);
        } catch (\Throwable) {
        }
    }

    private function parseSitemapXml(string $sitemapUrl, string $baseHost, array &$queue): void
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => self::UA])->timeout(8)->get($sitemapUrl);
            if (!$resp->successful())
                return;

            $xml = @simplexml_load_string($resp->body());
            if (!$xml)
                return;

            // Sitemap index
            foreach ($xml->sitemap ?? [] as $sm) {
                $loc = (string) ($sm->loc ?? '');
                if ($loc)
                    $this->parseSitemapXml($loc, $baseHost, $queue);
            }

            // URL set
            foreach ($xml->url ?? [] as $urlNode) {
                $loc = (string) ($urlNode->loc ?? '');
                $host = strtolower(parse_url($loc, PHP_URL_HOST) ?? '');
                if ($loc && $host === $baseHost && !in_array($loc, $queue, true)) {
                    $queue[] = $loc;
                }
            }
        } catch (\Throwable) {
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase B — Analytics Enrichment
    // ─────────────────────────────────────────────────────────────────────────

    private function enrichAnalytics(int $domainId, array $crawlData, int $days, ClickHouseService $ch): array
    {
        $from = now()->subDays($days)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');

        // Pageviews + unique visitors per URL
        $pageviews = $ch->select("
            SELECT url,
                   count()         AS pageviews,
                   uniq(visitor_id) AS unique_visitors
            FROM events
            WHERE domain_id = {$domainId}
              AND type = 'pageview'
              AND ts >= '{$from}' AND ts < '{$to}'
            GROUP BY url
        ");

        // Entry URL counts from sessions
        $entries = $ch->select("
            SELECT entry_url AS url,
                   count()   AS entry_count
            FROM sessions
            WHERE domain_id = {$domainId}
              AND started_at >= '{$from}' AND started_at < '{$to}'
            GROUP BY entry_url
        ");

        // Average click depth per URL (position within session)
        $depths = $ch->select("
            SELECT url, avg(depth_in_session) AS avg_depth
            FROM (
                SELECT session_id, url,
                       row_number() OVER (PARTITION BY session_id ORDER BY ts) AS depth_in_session
                FROM events
                WHERE domain_id = {$domainId}
                  AND type = 'pageview'
                  AND ts >= '{$from}' AND ts < '{$to}'
            )
            GROUP BY url
        ");

        // Index by URL
        $pvMap = array_column($pageviews, null, 'url');
        $entryMap = array_column($entries, null, 'url');
        $depthMap = array_column($depths, null, 'url');
        $crawlUrls = array_column($crawlData, 'url');

        // Determine traffic classification thresholds
        $allPageviews = array_map(fn($r) => (int) $r['pageviews'], $pageviews);
        sort($allPageviews);
        $count = count($allPageviews);
        $top20 = $count > 0 ? ($allPageviews[(int) floor($count * 0.8)] ?? 0) : 0;
        $bot20 = $count > 0 ? ($allPageviews[(int) floor($count * 0.2)] ?? 0) : 0;

        $analyticsUrls = array_keys($pvMap);

        $result = [];

        // URLs found by crawler
        foreach ($crawlUrls as $url) {
            $pv = isset($pvMap[$url]) ? (int) $pvMap[$url]['pageviews'] : 0;
            $uv = isset($pvMap[$url]) ? (int) $pvMap[$url]['unique_visitors'] : 0;
            $ec = isset($entryMap[$url]) ? (int) $entryMap[$url]['entry_count'] : 0;
            $ad = isset($depthMap[$url]) ? round((float) $depthMap[$url]['avg_depth'], 1) : null;

            if ($pv === 0) {
                $label = 'zero_traffic';
            } elseif ($pv >= $top20) {
                $label = 'high_traffic';
            } elseif ($pv <= $bot20) {
                $label = 'low_traffic';
            } else {
                $label = 'medium_traffic';
            }

            $result[] = [
                'url' => $url,
                'pageviews' => $pv,
                'unique_visitors' => $uv,
                'entry_count' => $ec,
                'avg_depth' => $ad,
                'traffic_label' => $label,
                'source' => 'crawl',
            ];
        }

        // Analytics-only URLs (in analytics but not found by crawler)
        foreach ($analyticsUrls as $url) {
            if (!in_array($url, $crawlUrls, true)) {
                $pv = (int) $pvMap[$url]['pageviews'];
                $uv = (int) $pvMap[$url]['unique_visitors'];
                $ec = isset($entryMap[$url]) ? (int) $entryMap[$url]['entry_count'] : 0;
                $ad = isset($depthMap[$url]) ? round((float) $depthMap[$url]['avg_depth'], 1) : null;

                $result[] = [
                    'url' => $url,
                    'pageviews' => $pv,
                    'unique_visitors' => $uv,
                    'entry_count' => $ec,
                    'avg_depth' => $ad,
                    'traffic_label' => 'analytics_only',
                    'source' => 'analytics',
                ];
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase C — Claude AI Analysis
    // ─────────────────────────────────────────────────────────────────────────

    private function analyzeWithAI(string $startUrl, array $crawlData, array $analyticsData, AnthropicService $anthropic): array
    {
        // Build a condensed summary for Claude
        $urlSample = array_slice(array_column($crawlData, 'url'), 0, 30);
        $titleSample = array_slice(
            array_filter(array_column($crawlData, 'title')),
            0,
            20
        );

        $totalCrawled = count($crawlData);
        $zeroTraffic = count(array_filter($analyticsData, fn($u) => $u['traffic_label'] === 'zero_traffic'));
        $analyticsOnly = count(array_filter($analyticsData, fn($u) => $u['traffic_label'] === 'analytics_only'));
        $highTraffic = count(array_filter($analyticsData, fn($u) => $u['traffic_label'] === 'high_traffic'));

        $systemPrompt = <<<'SYS'
You are an expert SEO consultant analyzing a website structure.
Your task is to detect the website type and produce a sitemap strategy.
Respond ONLY with valid JSON — no markdown, no explanation.
SYS;

        $userMessage = json_encode([
            'start_url' => $startUrl,
            'total_pages_crawled' => $totalCrawled,
            'zero_traffic_pages' => $zeroTraffic,
            'analytics_only_pages' => $analyticsOnly,
            'high_traffic_pages' => $highTraffic,
            'url_sample' => $urlSample,
            'title_sample' => $titleSample,
            'task' => 'Return JSON with keys: site_type (ecommerce|blog|saas|portfolio|news|docs|other), site_type_confidence (0-1), strategy (string), priority_rules (object mapping page_type to float 0-1), changefreq_rules (object mapping page_type to always|hourly|daily|weekly|monthly|yearly|never), recommendations (array of strings).',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            $result = $anthropic->complete($systemPrompt, $userMessage, 1000);
            return $result ?: $this->defaultAiAnalysis();
        } catch (\Throwable $e) {
            Log::warning('Sitemap AI analysis failed, using defaults', ['error' => $e->getMessage()]);
            return $this->defaultAiAnalysis();
        }
    }

    private function defaultAiAnalysis(): array
    {
        return [
            'site_type' => 'other',
            'site_type_confidence' => 0.5,
            'strategy' => 'Generic site — using standard priority rules.',
            'priority_rules' => [
                'homepage' => 1.0,
                'section' => 0.8,
                'page' => 0.6,
                'utility' => 0.3,
            ],
            'changefreq_rules' => [
                'homepage' => 'daily',
                'section' => 'weekly',
                'page' => 'monthly',
                'utility' => 'yearly',
            ],
            'recommendations' => [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase D — Build Sitemap
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSitemap(array $crawlData, array $analyticsData, array $aiAnalysis, bool $includeZero, bool $includeOnly): array
    {
        // Index analytics by URL for fast lookup
        $analyticsMap = array_column($analyticsData, null, 'url');

        $result = [];
        $crawlUrls = [];

        foreach ($crawlData as $page) {
            $url = $page['url'];
            $crawlUrls[] = $url;

            if ($page['status_code'] >= 400)
                continue;

            $analytics = $analyticsMap[$url] ?? null;
            $trafficLabel = $analytics['traffic_label'] ?? 'crawl_only';

            if (!$includeZero && $trafficLabel === 'zero_traffic')
                continue;

            $priority = $this->computePriority($url, $trafficLabel, $aiAnalysis);
            $changefreq = $this->computeChangefreq($url, $aiAnalysis);

            $result[] = [
                'url' => $url,
                'priority' => $priority,
                'changefreq' => $changefreq,
                'lastmod' => $page['last_modified'],
                'title' => $page['title'],
                'canonical' => $page['canonical'],
                'depth' => $page['depth'],
                'status_code' => $page['status_code'],
                'traffic_label' => $trafficLabel,
                'pageviews' => $analytics['pageviews'] ?? 0,
                'unique_visitors' => $analytics['unique_visitors'] ?? 0,
                'entry_count' => $analytics['entry_count'] ?? 0,
                'avg_depth' => $analytics['avg_depth'] ?? null,
                'source' => 'crawl',
            ];
        }

        // Add analytics-only URLs (not found by crawler)
        if ($includeOnly) {
            foreach ($analyticsData as $row) {
                if ($row['traffic_label'] !== 'analytics_only')
                    continue;
                if (in_array($row['url'], $crawlUrls, true))
                    continue;

                $priority = $this->computePriority($row['url'], 'analytics_only', $aiAnalysis);
                $changefreq = $this->computeChangefreq($row['url'], $aiAnalysis);

                $result[] = [
                    'url' => $row['url'],
                    'priority' => $priority,
                    'changefreq' => $changefreq,
                    'lastmod' => null,
                    'title' => null,
                    'canonical' => null,
                    'depth' => $row['avg_depth'] ?? null,
                    'status_code' => null,
                    'traffic_label' => 'analytics_only',
                    'pageviews' => $row['pageviews'],
                    'unique_visitors' => $row['unique_visitors'],
                    'entry_count' => $row['entry_count'],
                    'avg_depth' => $row['avg_depth'],
                    'source' => 'analytics',
                ];
            }
        }

        // Sort by priority desc
        usort($result, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $result;
    }

    private function computePriority(string $url, string $trafficLabel, array $ai): float
    {
        $base = self::PRIORITY_MAP[$trafficLabel] ?? 0.5;

        // Boost homepage
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        if ($path === '/' || $path === '') {
            return 1.0;
        }

        // Apply AI modifier: if URL looks like a category/section, boost it
        $priorityRules = $ai['priority_rules'] ?? [];
        $modifier = 1.0;
        if (!empty($priorityRules)) {
            $depth = substr_count(trim($path, '/'), '/');
            if ($depth === 0) {
                $modifier = (float) ($priorityRules['section'] ?? $priorityRules['category_pages'] ?? 1.0);
            } elseif ($depth === 1) {
                $modifier = (float) ($priorityRules['page'] ?? $priorityRules['product_pages'] ?? $priorityRules['blog_posts'] ?? 1.0);
            }
        }

        return round(min(1.0, $base * $modifier), 1);
    }

    private function computeChangefreq(string $url, array $ai): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $changefreqRules = $ai['changefreq_rules'] ?? [];

        if ($path === '/' || $path === '') {
            return $changefreqRules['homepage'] ?? 'daily';
        }

        $depth = substr_count(trim($path, '/'), '/');
        if ($depth === 0) {
            return $changefreqRules['section'] ?? $changefreqRules['category_pages'] ?? 'weekly';
        }

        return $changefreqRules['page'] ?? $changefreqRules['blog_posts'] ?? $changefreqRules['product_pages'] ?? 'monthly';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // XML Generation
    // ─────────────────────────────────────────────────────────────────────────

    private function generateXml(array $sitemapResult): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="https://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($sitemapResult as $entry) {
            $url = htmlspecialchars($entry['url'], ENT_XML1, 'UTF-8');
            $prio = number_format((float) $entry['priority'], 1);
            $freq = htmlspecialchars($entry['changefreq'] ?? 'monthly', ENT_XML1, 'UTF-8');

            $xml .= "  <url>\n";
            $xml .= "    <loc>{$url}</loc>\n";
            $xml .= "    <priority>{$prio}</priority>\n";
            $xml .= "    <changefreq>{$freq}</changefreq>\n";
            if (!empty($entry['lastmod'])) {
                $xml .= "    <lastmod>" . htmlspecialchars($entry['lastmod'], ENT_XML1, 'UTF-8') . "</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTML Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return null;
    }

    private function extractCanonical(string $html, string $pageUrl): ?string
    {
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractInternalLinks(string $html, string $pageUrl, string $baseHost, string $scheme): array
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        $parsed = parse_url($pageUrl);
        $basePath = isset($parsed['path']) ? dirname($parsed['path']) : '/';

        $links = [];
        foreach ($matches[1] as $href) {
            $href = trim($href);
            if (
                !$href || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')
            ) {
                continue;
            }
            if (str_starts_with($href, '//')) {
                $href = $scheme . ':' . $href;
            } elseif (str_starts_with($href, '/')) {
                $href = $scheme . '://' . $baseHost . $href;
            } elseif (!str_starts_with($href, 'http')) {
                $href = $scheme . '://' . $baseHost . rtrim($basePath, '/') . '/' . $href;
            }
            $href = strtok($href, '#') ?: $href;
            $linkHost = strtolower(parse_url($href, PHP_URL_HOST) ?? '');
            if ($linkHost === $baseHost && !in_array($href, $links, true)) {
                $links[] = $href;
            }
        }
        return $links;
    }

    private function normaliseUrl(string $url): string
    {
        return rtrim(strtok($url, '#') ?: $url, '/');
    }

    private function calculateDepth(string $url, string $startUrl): int
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        return max(0, substr_count(trim($path, '/'), '/'));
    }
}
