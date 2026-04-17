<?php

namespace App\Jobs;

use App\Events\RealtimeVisitorUpdate;
use App\Services\ClickHouseService;
use App\Services\GeoIpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class ProcessTrackingEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public readonly array $payload)
    {
    }

    public function handle(ClickHouseService $clickhouse, GeoIpService $geoip): void
    {
        $p = $this->payload;

        $geo = $geoip->lookup($p['ip']);

        $row = [
            'domain_id' => (int) $p['domain_id'],
            'session_id' => $p['session_id'],
            'visitor_id' => $p['visitor_id'],
            'type' => $p['type'],
            'url' => (string) ($p['url'] ?? ''),
            'referrer' => (string) ($p['referrer'] ?? ''),
            'title' => (string) ($p['title'] ?? ''),
            'props' => $p['props'] ?? [],
            'screen_w' => (int) ($p['screen_w'] ?? 0),
            'screen_h' => (int) ($p['screen_h'] ?? 0),
            'duration' => (int) ($p['duration'] ?? 0),
            'country' => $geo['country'] ?? '',
            'region' => $geo['region'] ?? '',
            'city' => $geo['city'] ?? '',
            'os' => $this->parseOs($p['user_agent'] ?? ''),
            'browser' => $this->parseBrowser($p['user_agent'] ?? ''),
            'device_type' => $this->parseDevice($p['user_agent'] ?? '', (int) ($p['screen_w'] ?? 0)),
            'ip_hash' => hash('sha256', (string) ($p['ip'] ?? '')),
            'ts' => $p['ts'],
        ];

        $clickhouse->insertJson('events', [$row]);

        // Also write to custom_events for fast custom event queries
        if ($row['type'] === 'custom' && !empty($p['props']['e'])) {
            $clickhouse->insertJson('custom_events', [
                [
                    'domain_id' => $row['domain_id'],
                    'session_id' => $row['session_id'],
                    'visitor_id' => $row['visitor_id'],
                    'name' => $p['props']['e'],
                    'props' => $p['props'],
                    'url' => $row['url'],
                    'ts' => $row['ts'],
                ]
            ]);
        }

        // Update realtime active-visitors sorted set in Redis
        $rtKey = "eye:realtime:{$row['domain_id']}";
        $cutoff = now()->subMinutes(5)->timestamp;
        Redis::zadd($rtKey, now()->timestamp, $row['visitor_id']);
        Redis::zremrangebyscore($rtKey, '-inf', (string) $cutoff);
        Redis::expire($rtKey, 600);

        $active = (int) Redis::zcard($rtKey);
        broadcast(new RealtimeVisitorUpdate($row['domain_id'], $active));
    }

    private function parseOs(string $ua): string
    {
        if (str_contains($ua, 'Windows'))
            return 'Windows';
        if (str_contains($ua, 'Mac OS'))
            return 'macOS';
        if (str_contains($ua, 'iPhone'))
            return 'iOS';
        if (str_contains($ua, 'iPad'))
            return 'iOS';
        if (str_contains($ua, 'Android'))
            return 'Android';
        if (str_contains($ua, 'Linux'))
            return 'Linux';
        if (str_contains($ua, 'CrOS'))
            return 'ChromeOS';
        return 'Other';
    }

    private function parseBrowser(string $ua): string
    {
        if (str_contains($ua, 'Edg/'))
            return 'Edge';
        if (str_contains($ua, 'OPR/'))
            return 'Opera';
        if (str_contains($ua, 'Chrome/'))
            return 'Chrome';
        if (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/'))
            return 'Safari';
        if (str_contains($ua, 'Firefox/'))
            return 'Firefox';
        if (str_contains($ua, 'MSIE') || str_contains($ua, 'Trident/'))
            return 'IE';
        return 'Other';
    }

    private function parseDevice(string $ua, int $screenW): string
    {
        foreach (['bot', 'crawler', 'spider', 'slurp', 'curl', 'wget'] as $bot) {
            if (str_contains(strtolower($ua), $bot))
                return 'bot';
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPod'))
            return 'mobile';
        if (str_contains($ua, 'Android') && str_contains($ua, 'Mobile'))
            return 'mobile';
        if (str_contains($ua, 'iPad') || str_contains($ua, 'Android'))
            return 'tablet';
        if ($screenW > 0 && $screenW < 768)
            return 'mobile';
        return 'desktop';
    }
}
