<?php

namespace App\Http\Controllers\Tools\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Shared SSRF guard for tools that fetch a user-supplied URL server-side.
 * Any public (unauthenticated) fetch-a-URL tool must use this — auth-gated
 * ones benefit too since it's a pure hardening with no behavior change for
 * legitimate URLs.
 */
trait GuardsSsrf
{
    /** Reject loopback / private / link-local / metadata addresses to prevent SSRF. */
    protected function isPublicHost(string $host): bool
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

    /**
     * Follows redirects manually (disabling curl's built-in following) so each
     * hop's destination host is re-validated — otherwise a URL that passes the
     * initial SSRF check could redirect to an internal address afterward.
     */
    protected function fetchWithSafeRedirects(string $url, string $userAgent, int $timeout, int $hopsLeft = 3)
    {
        $response = Http::withHeaders(['User-Agent' => $userAgent])
            ->timeout($timeout)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if (in_array($response->status(), [301, 302, 303, 307, 308], true) && $hopsLeft > 0) {
            $location = $response->header('Location');
            if (!$location) {
                return $response;
            }
            $next = filter_var($location, FILTER_VALIDATE_URL) ? $location : $this->resolveRelativeUrl($url, $location);
            $nextHost = parse_url($next, PHP_URL_HOST);
            $nextScheme = strtolower(parse_url($next, PHP_URL_SCHEME) ?? '');
            if (!$nextHost || !in_array($nextScheme, ['http', 'https'], true) || !$this->isPublicHost($nextHost)) {
                return null;
            }

            return $this->fetchWithSafeRedirects($next, $userAgent, $timeout, $hopsLeft - 1);
        }

        return $response;
    }

    protected function resolveRelativeUrl(string $base, string $location): string
    {
        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';

        return str_starts_with($location, '/') ? "{$scheme}://{$host}{$location}" : $location;
    }

    /** Scheme + resolvable public host, in one call. */
    protected function isSafeUrl(string $url): bool
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && $host !== '' && $this->isPublicHost($host);
    }
}
