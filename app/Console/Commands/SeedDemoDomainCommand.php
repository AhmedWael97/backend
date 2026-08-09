<?php

namespace App\Console\Commands;

use App\Models\AdSpend;
use App\Models\Domain;
use App\Models\Pipeline;
use App\Models\PipelineStep;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates (or refreshes) the one shared sandbox domain — real Postgres row,
 * real ClickHouse events, so every real dashboard page works against it with
 * zero per-page mocking. Safe to re-run: wipes and regenerates its own
 * ClickHouse rows only (scoped to its own domain_id), touches no other data.
 */
class SeedDemoDomainCommand extends Command
{
    protected $signature = 'eye:seed-demo-domain';

    protected $description = 'Create/refresh the shared sandbox domain with realistic seeded data.';

    private const PAGES = ['/', '/pricing', '/blog/getting-started', '/features', '/checkout', '/contact'];
    private const COUNTRIES = ['EG', 'SA', 'AE', 'US', 'GB'];
    private const COUNTRY_WEIGHTS = [40, 20, 15, 15, 10];
    private const DEVICES = ['desktop', 'mobile', 'tablet'];
    private const DEVICE_WEIGHTS = [55, 38, 7];
    private const BROWSERS = ['Chrome', 'Safari', 'Firefox', 'Edge'];
    private const OS = ['Windows', 'macOS', 'iOS', 'Android'];
    private const CHANNELS = [
        ['source' => '', 'medium' => ''],
        ['source' => '', 'medium' => ''],
        ['source' => 'google', 'medium' => 'cpc'],
        ['source' => 'facebook', 'medium' => 'paid'],
        ['source' => 'instagram', 'medium' => 'paid'],
        ['source' => 'newsletter', 'medium' => 'email'],
        ['source' => 'google', 'medium' => 'organic'],
    ];

    public function handle(ClickHouseService $ch): int
    {
        $owner = User::firstOrCreate(
            ['email' => 'demo-sandbox@eye-analysis.online'],
            [
                'name' => 'EYE Sandbox',
                'password' => Str::random(40),
                'role' => 'user',
                'email_verified_at' => now(),
            ]
        );

        $domain = Domain::firstOrCreate(
            ['is_demo' => true],
            [
                'user_id' => $owner->id,
                'domain' => 'demo-sandbox.eye-analysis.online',
                'active' => true,
                'script_verified_at' => now(),
            ]
        );

        $this->info("Sandbox domain: #{$domain->id} ({$domain->domain})");

        // Wipe this domain's own ClickHouse rows only — safe to re-run.
        foreach (['events', 'conversions', 'pipeline_events'] as $table) {
            $ch->execute("ALTER TABLE {$table} DELETE WHERE domain_id = {$domain->id}");
        }

        $this->seedEvents($ch, $domain->id);
        $this->seedFunnel($ch, $domain);
        $this->seedAdSpend($domain->id);

        $this->info('Sandbox domain seeded.');

        return self::SUCCESS;
    }

    private function weightedPick(array $items, array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $i => $w) {
            if ($roll <= $w) {
                return $items[$i];
            }
            $roll -= $w;
        }
        return $items[0];
    }

    private function seedEvents(ClickHouseService $ch, int $domainId): void
    {
        $rows = [];
        $conversions = [];
        $days = 30;

        for ($d = $days; $d >= 0; $d--) {
            $day = now()->subDays($d)->startOfDay();
            // Gentle growth trend over the window, weekday-ish variance.
            $base = 60 + (int) round((($days - $d) / $days) * 90);
            $sessionsToday = $base + mt_rand(-15, 20);

            for ($s = 0; $s < $sessionsToday; $s++) {
                $sessionId = Str::uuid()->toString();
                $visitorId = Str::uuid()->toString();
                $device = $this->weightedPick(self::DEVICES, self::DEVICE_WEIGHTS);
                $country = $this->weightedPick(self::COUNTRIES, self::COUNTRY_WEIGHTS);
                $browser = self::BROWSERS[array_rand(self::BROWSERS)];
                $os = self::OS[array_rand(self::OS)];
                $channel = self::CHANNELS[array_rand(self::CHANNELS)];
                $sessionStart = $day->copy()->addMinutes(mt_rand(0, 1439));

                $pagesThisSession = mt_rand(1, 4);
                $visited = (array) array_rand(array_flip(self::PAGES), min($pagesThisSession, count(self::PAGES)));
                if (!is_array($visited)) $visited = [$visited];

                $t = $sessionStart->copy();
                foreach ($visited as $url) {
                    $duration = mt_rand(15, 180);
                    $rows[] = [
                        'domain_id' => $domainId,
                        'session_id' => $sessionId,
                        'visitor_id' => $visitorId,
                        'type' => 'pageview',
                        'url' => $url,
                        'referrer' => $channel['source'] ? "https://{$channel['source']}.com/" : '',
                        'title' => '',
                        'props' => '{}',
                        'screen_w' => $device === 'mobile' ? 390 : ($device === 'tablet' ? 810 : 1440),
                        'screen_h' => $device === 'mobile' ? 844 : ($device === 'tablet' ? 1080 : 900),
                        'duration' => $duration,
                        'country' => $country,
                        'region' => '',
                        'city' => '',
                        'os' => $os,
                        'browser' => $browser,
                        'device_type' => $device,
                        'ip_hash' => substr(md5($visitorId), 0, 16),
                        'utm_source' => $channel['source'],
                        'utm_medium' => $channel['medium'],
                        'utm_campaign' => $channel['source'] ? 'sandbox_demo' : '',
                        'utm_term' => '',
                        'utm_content' => '',
                        'ts' => $t->format('Y-m-d H:i:s'),
                    ];

                    // Click events on the two pages the sandbox heatmap page highlights.
                    if (in_array($url, ['/', '/pricing'], true) && mt_rand(1, 100) <= 70) {
                        $clicks = mt_rand(1, 3);
                        for ($c = 0; $c < $clicks; $c++) {
                            $x = round(min(96, max(4, 50 + $this->gauss() * 18)), 1);
                            $y = round(min(96, max(4, ($url === '/' ? 35 : 55) + $this->gauss() * 16)), 1);
                            $rows[] = [
                                'domain_id' => $domainId,
                                'session_id' => $sessionId,
                                'visitor_id' => $visitorId,
                                'type' => 'click',
                                'url' => $url,
                                'referrer' => '',
                                'title' => '',
                                'props' => json_encode(['x' => $x, 'y' => $y]),
                                'screen_w' => 1440, 'screen_h' => 900, 'duration' => 0,
                                'country' => $country, 'region' => '', 'city' => '',
                                'os' => $os, 'browser' => $browser, 'device_type' => $device,
                                'ip_hash' => substr(md5($visitorId), 0, 16),
                                'utm_source' => $channel['source'], 'utm_medium' => $channel['medium'],
                                'utm_campaign' => $channel['source'] ? 'sandbox_demo' : '', 'utm_term' => '', 'utm_content' => '',
                                'ts' => $t->copy()->addSeconds(mt_rand(2, 20))->format('Y-m-d H:i:s'),
                            ];
                        }
                    }

                    $t->addSeconds($duration + mt_rand(5, 40));
                }

                // ~9% of sessions that reached /checkout convert.
                if (in_array('/checkout', $visited, true) && mt_rand(1, 100) <= 55) {
                    $conversions[] = [
                        'domain_id' => $domainId,
                        'order_id' => 'SANDBOX-' . strtoupper(Str::random(8)),
                        'session_id' => $sessionId,
                        'visitor_id' => $visitorId,
                        'value' => mt_rand(19, 149) + 0.99,
                        'currency' => 'USD',
                        'name' => 'purchase',
                        'url' => '/checkout',
                        'ts' => $t->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 2000) as $chunk) {
            $ch->insertJson('events', $chunk);
        }
        if ($conversions) {
            $ch->insertJson('conversions', $conversions);
        }

        $this->info(count($rows) . ' events, ' . count($conversions) . ' conversions seeded.');
    }

    /** Approximate a normal-ish spread without needing an external stats lib. */
    private function gauss(): float
    {
        $u1 = mt_rand(1, 1000000) / 1000000;
        $u2 = mt_rand(1, 1000000) / 1000000;
        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    private function seedFunnel(ClickHouseService $ch, Domain $domain): void
    {
        Pipeline::where('domain_id', $domain->id)->delete();
        $pipeline = Pipeline::create([
            'domain_id' => $domain->id,
            'name' => 'Checkout funnel',
            'description' => 'Home → Pricing → Checkout',
        ]);
        $steps = [
            PipelineStep::create(['pipeline_id' => $pipeline->id, 'name' => 'Landed', 'url_pattern' => '/', 'match_type' => 'equals', 'order' => 0]),
            PipelineStep::create(['pipeline_id' => $pipeline->id, 'name' => 'Viewed pricing', 'url_pattern' => '/pricing', 'match_type' => 'equals', 'order' => 1]),
            PipelineStep::create(['pipeline_id' => $pipeline->id, 'name' => 'Reached checkout', 'url_pattern' => '/checkout', 'match_type' => 'equals', 'order' => 2]),
        ];

        // Realistic drop-off: everyone hits step 0, ~45% step 1, ~18% step 2.
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $sessionId = Str::uuid()->toString();
            $ts = now()->subDays(mt_rand(0, 29))->subMinutes(mt_rand(0, 1439))->format('Y-m-d H:i:s');
            $rows[] = ['domain_id' => $domain->id, 'session_id' => $sessionId, 'pipeline_id' => $pipeline->id, 'step_id' => $steps[0]->id, 'status' => 'completed', 'event_time' => $ts];
            if (mt_rand(1, 100) <= 45) {
                $rows[] = ['domain_id' => $domain->id, 'session_id' => $sessionId, 'pipeline_id' => $pipeline->id, 'step_id' => $steps[1]->id, 'status' => 'completed', 'event_time' => $ts];
                if (mt_rand(1, 100) <= 40) {
                    $rows[] = ['domain_id' => $domain->id, 'session_id' => $sessionId, 'pipeline_id' => $pipeline->id, 'step_id' => $steps[2]->id, 'status' => 'completed', 'event_time' => $ts];
                }
            }
        }
        $ch->insertJson('pipeline_events', $rows);
    }

    private function seedAdSpend(int $domainId): void
    {
        AdSpend::where('domain_id', $domainId)->delete();
        $campaigns = [
            ['source' => 'google', 'campaign' => 'search_brand'],
            ['source' => 'facebook', 'campaign' => 'prospecting_q3'],
            ['source' => 'instagram', 'campaign' => 'retarget_checkout'],
        ];
        foreach ($campaigns as $c) {
            for ($d = 13; $d >= 0; $d--) {
                AdSpend::create([
                    'domain_id' => $domainId,
                    'date' => now()->subDays($d)->toDateString(),
                    'source' => $c['source'],
                    'campaign' => $c['campaign'],
                    'medium' => 'paid',
                    'spend' => mt_rand(15, 60) + mt_rand(0, 99) / 100,
                    'currency' => 'USD',
                    'clicks' => mt_rand(20, 150),
                    'impressions' => mt_rand(800, 6000),
                ]);
            }
        }
    }
}
