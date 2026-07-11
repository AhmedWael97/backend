<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tools\Concerns\GuardsSsrf;
use App\Models\ToolUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /tools/sitemap-check — public, no login. Lightweight sitemap generator:
 * BFS-crawls internal links and returns ready-to-use sitemap.xml.
 *
 * Deliberately separate from SitemapController (the authenticated, job-based,
 * analytics-enriched generator) — that one is queued and tied to a user_id;
 * this is synchronous and capped low, same shape as the other free tools.
 */
class PublicSitemapController extends Controller
{
    use GuardsSsrf;

    private const TIMEOUT = 8;
    private const MAX_BYTES = 1024 * 1024; // 1 MB cap per page
    private const UA = 'Mozilla/5.0 (compatible; EyeSitemapBot/1.0)';
    private const MAX_PAGES = 15;

    public function generate(Request $request): JsonResponse
    {
        $request->validate(['url' => ['required', 'url', 'max:2048']]);
        $startUrl = rtrim((string) $request->input('url'), '/');

        if (!$this->isSafeUrl($startUrl)) {
            return $this->error('This URL cannot be crawled.', 422);
        }

        $baseHost = strtolower(parse_url($startUrl, PHP_URL_HOST) ?? '');

        $visited = [];
        $queue = [$startUrl];
        $pages = [];

        while (!empty($queue) && count($pages) < self::MAX_PAGES) {
            $url = array_shift($queue);
            $normalized = $this->normalizeUrl($url);
            if (isset($visited[$normalized])) {
                continue;
            }
            $visited[$normalized] = true;

            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            if ($host !== $baseHost) {
                continue; // same-host only
            }

            try {
                $response = $this->fetchWithSafeRedirects($url, self::UA, self::TIMEOUT);
            } catch (\Throwable $e) {
                continue;
            }
            if ($response === null || !$response->successful()) {
                continue;
            }

            $html = substr($response->body(), 0, self::MAX_BYTES);
            $depth = substr_count(parse_url($url, PHP_URL_PATH) ?? '/', '/');
            $pages[] = [
                'url' => $url,
                'priority' => $url === $startUrl ? '1.0' : ($depth <= 1 ? '0.8' : '0.5'),
                'changefreq' => $url === $startUrl ? 'weekly' : 'monthly',
            ];

            foreach ($this->extractInternalLinks($html, $url, $baseHost) as $link) {
                $norm = $this->normalizeUrl($link);
                if (!isset($visited[$norm]) && !in_array($link, $queue, true)) {
                    $queue[] = $link;
                }
            }
        }

        if (empty($pages)) {
            return $this->error('Could not crawl this URL.', 422);
        }

        $xml = $this->buildXml($pages);

        ToolUsageLog::log($request, 'sitemap_creator', $startUrl, count($pages));

        return $this->success([
            'start_url' => $startUrl,
            'pages_crawled' => count($pages),
            'truncated' => count($pages) >= self::MAX_PAGES,
            'pages' => $pages,
            'xml' => $xml,
        ]);
    }

    private function buildXml(array $pages): string
    {
        $today = now()->format('Y-m-d');
        $entries = array_map(function ($p) use ($today) {
            $loc = htmlspecialchars($p['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$today}</lastmod>\n    <changefreq>{$p['changefreq']}</changefreq>\n    <priority>{$p['priority']}</priority>\n  </url>";
        }, $pages);

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . implode("\n", $entries) . "\n</urlset>\n";
    }

    /** @return string[] */
    private function extractInternalLinks(string $html, string $pageUrl, string $baseHost): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//a[@href]');
        if ($links === false) {
            return [];
        }

        $parsed = parse_url($pageUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? $baseHost;
        $basePath = isset($parsed['path']) ? dirname($parsed['path']) : '/';

        $collected = [];
        foreach ($links as $link) {
            $href = trim($link->getAttribute('href') ?? '');
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            if (str_starts_with($href, '//')) {
                $href = $scheme . ':' . $href;
            } elseif (str_starts_with($href, '/')) {
                $href = $scheme . '://' . $host . $href;
            } elseif (!str_starts_with($href, 'http')) {
                $href = $scheme . '://' . $host . rtrim($basePath, '/') . '/' . $href;
            }
            $linkHost = strtolower(parse_url($href, PHP_URL_HOST) ?? '');
            if ($linkHost !== $baseHost) {
                continue;
            }
            $href = strtok($href, '#');
            if ($href && !in_array($href, $collected, true)) {
                $collected[] = $href;
            }
        }

        return $collected;
    }

    private function normalizeUrl(string $url): string
    {
        $url = strtok($url, '#');
        $url = rtrim($url, '/');

        return strtolower($url);
    }
}
