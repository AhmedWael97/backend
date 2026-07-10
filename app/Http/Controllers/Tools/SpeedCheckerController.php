<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * POST /tools/speed-check — public, no login. Lead-magnet tool: fetches a URL
 * server-side and scores page-load basics (TTFB, size, compression, caching,
 * render-blocking scripts). No headless browser — heuristic checklist, same
 * shape as SeoCheckerController.
 *
 * Public + unauthenticated, so (unlike the auth-gated SEO checker) this must
 * guard against SSRF: only fetch public internet hosts, never internal/
 * loopback/link-local/metadata addresses.
 */
class SpeedCheckerController extends Controller
{
    private const TIMEOUT = 12;
    private const MAX_BYTES = 3 * 1024 * 1024;
    private const UA = 'Mozilla/5.0 (compatible; EyeSpeedBot/1.0)';

    public function check(Request $request): JsonResponse
    {
        $request->validate(['url' => ['required', 'url', 'max:2048']]);
        $url = (string) $request->input('url');

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->error('Only http/https URLs are allowed.', 422);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !$this->isPublicHost($host)) {
            return $this->error('This URL cannot be checked.', 422);
        }

        try {
            $start = microtime(true);
            $response = $this->fetchWithSafeRedirects($url);
            $totalMs = (int) round((microtime(true) - $start) * 1000);
        } catch (\Throwable $e) {
            return $this->error('Could not reach the URL: ' . $e->getMessage(), 422);
        }
        if ($response === null) {
            return $this->error('This URL cannot be checked.', 422);
        }

        if (!$response->successful()) {
            return $this->error("URL returned HTTP {$response->status()}.", 422);
        }

        $ttfbMs = $this->extractTtfb($response) ?? $totalMs;
        $html = substr($response->body(), 0, self::MAX_BYTES);
        $sizeKb = round(strlen($html) / 1024, 1);
        $headers = $response->headers();

        $checks = $this->runChecks($ttfbMs, $totalMs, $sizeKb, $html, $headers);
        $passed = count(array_filter($checks, fn ($c) => $c['status'] === 'pass'));
        $score = count($checks) > 0 ? (int) round(($passed / count($checks)) * 100) : 0;

        return $this->success([
            'url' => $url,
            'score' => $score,
            'ttfb_ms' => $ttfbMs,
            'total_ms' => $totalMs,
            'size_kb' => $sizeKb,
            'checks' => $checks,
        ]);
    }

    /**
     * Follows redirects manually (disabling curl's built-in following) so each
     * hop's destination host is re-validated — otherwise a URL that passes the
     * initial SSRF check could redirect to an internal address afterward.
     */
    private function fetchWithSafeRedirects(string $url, int $hopsLeft = 3)
    {
        $response = Http::withHeaders(['User-Agent' => self::UA])
            ->timeout(self::TIMEOUT)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if (in_array($response->status(), [301, 302, 303, 307, 308], true) && $hopsLeft > 0) {
            $location = $response->header('Location');
            if (!$location) {
                return $response;
            }
            $next = filter_var($location, FILTER_VALIDATE_URL) ? $location : $this->resolveRelative($url, $location);
            $nextHost = parse_url($next, PHP_URL_HOST);
            $nextScheme = strtolower(parse_url($next, PHP_URL_SCHEME) ?? '');
            if (!$nextHost || !in_array($nextScheme, ['http', 'https'], true) || !$this->isPublicHost($nextHost)) {
                return null;
            }

            return $this->fetchWithSafeRedirects($next, $hopsLeft - 1);
        }

        return $response;
    }

    private function resolveRelative(string $base, string $location): string
    {
        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';

        return str_starts_with($location, '/') ? "{$scheme}://{$host}{$location}" : $location;
    }

    /** Reject loopback / private / link-local / metadata addresses to prevent SSRF. */
    private function isPublicHost(string $host): bool
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            foreach ($records ?: [] as $r) {
                $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
            }
            $ips = array_filter($ips);
            if (empty($ips)) {
                return false; // couldn't resolve — refuse rather than let curl resolve unchecked
            }
        }

        foreach ($ips as $ip) {
            $isPublic = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if (!$isPublic) {
                return false;
            }
        }

        return true;
    }

    private function extractTtfb($response): ?int
    {
        $stats = $response->transferStats ?? null;
        if (!$stats) {
            return null;
        }
        $starttransfer = $stats->getHandlerStat('starttransfer_time');

        return $starttransfer !== null ? (int) round($starttransfer * 1000) : null;
    }

    /** @return array<int, array{id: string, label: string, status: string, detail: string}> */
    private function runChecks(int $ttfbMs, int $totalMs, float $sizeKb, string $html, $headers): array
    {
        $header = fn (string $name) => $headers[$name][0] ?? ($headers[strtolower($name)][0] ?? null);

        $checks = [];

        $checks[] = [
            'id' => 'ttfb',
            'label' => 'Server response time (TTFB)',
            'status' => $ttfbMs < 600 ? 'pass' : ($ttfbMs < 1500 ? 'warn' : 'fail'),
            'detail' => "{$ttfbMs}ms — under 600ms is fast, over 1500ms visitors notice the wait.",
        ];

        $checks[] = [
            'id' => 'total_time',
            'label' => 'Total load time',
            'status' => $totalMs < 1500 ? 'pass' : ($totalMs < 3500 ? 'warn' : 'fail'),
            'detail' => "{$totalMs}ms to fully download the page.",
        ];

        $checks[] = [
            'id' => 'html_size',
            'label' => 'HTML document size',
            'status' => $sizeKb < 100 ? 'pass' : ($sizeKb < 300 ? 'warn' : 'fail'),
            'detail' => "{$sizeKb} KB — smaller pages parse and render faster on mobile.",
        ];

        $encoding = $header('Content-Encoding');
        $checks[] = [
            'id' => 'compression',
            'label' => 'Compression (gzip/brotli)',
            'status' => $encoding ? 'pass' : 'fail',
            'detail' => $encoding ? "Served compressed ({$encoding})." : 'No compression detected — this can cut transfer size significantly.',
        ];

        $cacheControl = $header('Cache-Control');
        $checks[] = [
            'id' => 'caching',
            'label' => 'Cache headers',
            'status' => $cacheControl ? 'pass' : 'warn',
            'detail' => $cacheControl ? "Cache-Control: {$cacheControl}" : 'No Cache-Control header — repeat visits re-download everything.',
        ];

        $blockingScripts = preg_match('/<head[^>]*>.*?<\/head>/is', $html, $headMatch)
            ? preg_match_all('/<script(?![^>]*\b(async|defer)\b)[^>]*\ssrc=/i', $headMatch[0])
            : 0;
        $checks[] = [
            'id' => 'render_blocking',
            'label' => 'Render-blocking scripts in <head>',
            'status' => $blockingScripts === 0 ? 'pass' : ($blockingScripts <= 2 ? 'warn' : 'fail'),
            'detail' => $blockingScripts === 0
                ? 'No blocking scripts found in <head>.'
                : "{$blockingScripts} script(s) without async/defer in <head> — these delay first paint.",
        ];

        $imgCount = preg_match_all('/<img\b/i', $html);
        $lazyCount = preg_match_all('/<img\b[^>]*\bloading=["\']lazy["\']/i', $html);
        $checks[] = [
            'id' => 'lazy_images',
            'label' => 'Lazy-loaded images',
            'status' => $imgCount === 0 || $lazyCount >= $imgCount * 0.5 ? 'pass' : 'warn',
            'detail' => "{$lazyCount} of {$imgCount} <img> tags use loading=\"lazy\".",
        ];

        return $checks;
    }
}
