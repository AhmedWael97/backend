<?php

namespace App\Jobs;

use App\Events\RealtimeVisitorUpdate;
use App\Models\Domain;
use App\Models\User;
use App\Models\VisitorIdentity;
use App\Models\Webhook;
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

        // Extract UTM params from props
        $utmSource = (string) ($p['props']['utm_source'] ?? '');
        $utmMedium = (string) ($p['props']['utm_medium'] ?? '');
        $utmCampaign = (string) ($p['props']['utm_campaign'] ?? '');
        $utmTerm = (string) ($p['props']['utm_term'] ?? '');
        $utmContent = (string) ($p['props']['utm_content'] ?? '');

        $row['utm_source'] = $utmSource;
        $row['utm_medium'] = $utmMedium;
        $row['utm_campaign'] = $utmCampaign;
        $row['utm_term'] = $utmTerm;
        $row['utm_content'] = $utmContent;

        $clickhouse->insertJson('events', [$row]);

        // Write session record for pageview (entry event)
        if ($row['type'] === 'pageview') {
            // Company enrichment — check Redis cache first
            $ipHash = $row['ip_hash'];
            $companyName = null;
            $enrichCacheKey = "enrich:{$ipHash}";
            $cached = Redis::get($enrichCacheKey);
            if ($cached !== null) {
                $enriched = json_decode($cached, true);
                $companyName = $enriched['company_name'] ?? null;
            } elseif (config('services.ipinfo.token')) {
                EnrichCompanyJob::dispatch($ipHash, (string) ($p['ip'] ?? ''), $row['domain_id'], $row['session_id'])
                    ->onQueue('ai');
            }

            $clickhouse->insertJson('sessions', [
                [
                    'domain_id' => $row['domain_id'],
                    'session_id' => $row['session_id'],
                    'visitor_id' => $row['visitor_id'],
                    'duration_seconds' => 0,
                    'page_count' => 1,
                    'country' => $row['country'],
                    'device' => $row['device_type'],
                    'browser' => $row['browser'],
                    'os' => $row['os'],
                    'entry_url' => $row['url'],
                    'exit_url' => $row['url'],
                    'utm_source' => $utmSource,
                    'utm_medium' => $utmMedium,
                    'utm_campaign' => $utmCampaign,
                    'company_name' => $companyName,
                    'started_at' => $row['ts'],
                ]
            ]);
        }

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

        // Handle identify events — upsert visitor_identities
        if ($row['type'] === 'identify' && !empty($p['props']['uid'])) {
            VisitorIdentity::updateOrCreate(
                [
                    'domain_id' => $row['domain_id'],
                    'visitor_id' => $row['visitor_id'],
                ],
                [
                    'external_id' => substr((string) $p['props']['uid'], 0, 255),
                    'traits' => $p['props'],
                    'first_identified_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Dispatch webhooks for any active webhook subscribed to this event type
        $webhooks = Webhook::where('domain_id', $row['domain_id'])
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            $subscribedEvents = $webhook->events ?? [];
            if (in_array($row['type'], $subscribedEvents, true) || in_array('*', $subscribedEvents, true)) {
                WebhookDeliveryJob::dispatch($webhook->id, $row['type'], [
                    'domain_id' => $row['domain_id'],
                    'visitor_id' => $row['visitor_id'],
                    'session_id' => $row['session_id'],
                    'type' => $row['type'],
                    'url' => $row['url'],
                    'ts' => $row['ts'],
                ])->onQueue('notifications');

                $webhook->update(['last_triggered_at' => now()]);
            }
        }

        // Auto-update onboarding: mark script_installed + first_event_received
        $domain = Domain::find($row['domain_id']);
        if ($domain) {
            $user = User::find($domain->user_id);
            if ($user) {
                $onboarding = $user->onboarding ?? [];
                $changed = false;
                if (empty($onboarding['script_installed'])) {
                    $onboarding['script_installed'] = true;
                    $changed = true;
                }
                if (empty($onboarding['first_event_received'])) {
                    $onboarding['first_event_received'] = true;
                    $changed = true;
                }
                if ($changed) {
                    $user->update(['onboarding' => $onboarding]);
                }
            }
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
