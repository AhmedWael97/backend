<?php

namespace App\Services;

use MaxMind\Db\Reader;

/**
 * GeoIP lookup via MaxMind GeoLite2-City.mmdb.
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

        $reader = $this->getReader();
        if ($reader === null) {
            return $empty;
        }

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

    public function __destruct()
    {
        $this->reader?->close();
    }
}
