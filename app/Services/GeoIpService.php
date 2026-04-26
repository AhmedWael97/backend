<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use MaxMind\Db\Reader;

/**
 * GeoIP lookup via MaxMind GeoLite2-City.mmdb.
 * Falls back to ip-api.com HTTP lookup (cached in Redis 7 days) when no mmdb file is configured.
 *
 * Database file path: MAXMIND_DB_PATH env var (default: storage/app/geoip/GeoLite2-City.mmdb)
 * Download from: https://www.maxmind.com/en/geolite2/signup
 */
class GeoIpService
{
    private ?Reader $reader = null;

    private function getReader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        $path = config('services.maxmind.db_path');

        if (!$path || !file_exists($path)) {
            return null;
        }

        $this->reader = new Reader($path);
        return $this->reader;
    }

    /**
     * Lookup an IP address and return country, region, city.
     *
     * @return array{country: string, region: string, city: string}
     */
    public function lookup(string $ip): array
    {
        $empty = ['country' => '', 'region' => '', 'city' => ''];

        // Skip private/reserved IPs
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $empty;
        }

        // Try MaxMind mmdb first
        $reader = $this->getReader();
        if ($reader !== null) {
            try {
                $record = $reader->get($ip);

                if (!$record) {
                    return $empty;
                }

                return [
                    'country' => $record['country']['iso_code'] ?? '',
                    'region' => $record['subdivisions'][0]['iso_code'] ?? '',
                    'city' => $record['city']['names']['en'] ?? '',
                ];
            } catch (\Exception) {
                return $empty;
            }
        }

        // Fallback: ip-api.com (free, ~45 req/min) — cache in Redis for 7 days
        return $this->httpLookup($ip);
    }

    /**
     * HTTP-based GeoIP via ip-api.com with Redis caching.
     */
    private function httpLookup(string $ip): array
    {
        $empty = ['country' => '', 'region' => '', 'city' => ''];

        try {
            $cacheKey = "geoip:{$ip}";
            $cached = Redis::get($cacheKey);
            if ($cached !== null) {
                return json_decode($cached, true) ?? $empty;
            }

            $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,regionName,city";
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);

            if (!$body) {
                return $empty;
            }

            $data = json_decode($body, true);

            if (!$data || ($data['status'] ?? '') !== 'success') {
                return $empty;
            }

            $result = [
                'country' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? '',
                'city' => $data['city'] ?? '',
            ];

            // Cache for 7 days
            Redis::setex($cacheKey, 604800, json_encode($result));

            return $result;
        } catch (\Exception) {
            return $empty;
        }
    }

    public function __destruct()
    {
        $this->reader?->close();
    }
}
