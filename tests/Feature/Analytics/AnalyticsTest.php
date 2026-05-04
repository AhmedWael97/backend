<?php

use App\Models\Domain;
use App\Models\User;
use App\Services\AnalyticsQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helper: authenticated user + owned domain
|--------------------------------------------------------------------------
*/
function analyticsUser(): User
{
    return User::factory()->create(['status' => 'active']);
}

function analyticsDomain(User $user): Domain
{
    return Domain::factory()->create(['user_id' => $user->id, 'active' => true]);
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/stats', function () {

    it('requires authentication', function () {
        $domain = analyticsDomain(analyticsUser());

        $this->getJson("/api/domains/{$domain->id}/analytics/stats")
            ->assertStatus(401);
    });

    it('returns 404 for a domain owned by another user', function () {
        $owner = analyticsUser();
        $domain = analyticsDomain($owner);
        $other = analyticsUser();

        $this->actingAs($other)
            ->getJson("/api/domains/{$domain->id}/analytics/stats")
            ->assertStatus(404);
    });

    it('validates date inputs', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/stats?start=not-a-date")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start'], 'data.errors');
    });

    it('rejects end date before start date', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/stats?start=2026-01-10&end=2026-01-01")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start'], 'data.errors');
    });

    it('rejects invalid granularity', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/stats?granularity=second")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['granularity'], 'data.errors');
    });

    it('returns summary and timeseries from analytics service', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('stats')
            ->once()
            ->with($domain->id, Mockery::type(Carbon::class), Mockery::type(Carbon::class), 'day')
            ->andReturn([
                'summary' => [
                    'pageviews' => 1000,
                    'unique_visitors' => 250,
                    'sessions' => 310,
                    'bounce_rate' => 38.5,
                    'avg_duration' => 120,
                ],
                'timeseries' => [
                    ['period' => '2026-01-01', 'pageviews' => 120, 'unique_visitors' => 40, 'sessions' => 50],
                ],
            ]);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/stats?granularity=day")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['pageviews', 'unique_visitors', 'sessions', 'bounce_rate', 'avg_duration'],
                    'timeseries' => [['period', 'pageviews', 'unique_visitors', 'sessions']],
                ],
            ])
            ->assertJsonPath('data.summary.pageviews', 1000)
            ->assertJsonPath('data.summary.bounce_rate', 38.5);
    });
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/pages', function () {

    it('requires authentication', function () {
        $domain = analyticsDomain(analyticsUser());

        $this->getJson("/api/domains/{$domain->id}/analytics/pages")
            ->assertStatus(401);
    });

    it('returns 404 for unowned domain', function () {
        $domain = analyticsDomain(analyticsUser());

        $this->actingAs(analyticsUser())
            ->getJson("/api/domains/{$domain->id}/analytics/pages")
            ->assertStatus(404);
    });

    it('returns page data from analytics service', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('topPages')
            ->once()
            ->andReturn([
                ['url' => '/', 'pageviews' => 500, 'unique_visitors' => 200, 'avg_duration' => 90],
            ]);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/pages")
            ->assertOk()
            ->assertJsonStructure(['data' => [['url', 'pageviews', 'unique_visitors', 'avg_duration']]]);
    });
});

/*
|--------------------------------------------------------------------------
| Referrers
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/referrers', function () {

    it('returns referrer data from analytics service', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('topReferrers')
            ->once()
            ->andReturn([
                ['referrer' => 'https://google.com', 'visits' => 300, 'unique_visitors' => 150],
            ]);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/referrers")
            ->assertOk()
            ->assertJsonPath('data.0.referrer', 'https://google.com');
    });
});

/*
|--------------------------------------------------------------------------
| Devices
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/devices', function () {

    it('returns device breakdown', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('devices')
            ->once()
            ->andReturn([
                'browsers' => [['browser' => 'Chrome', 'visits' => 800, 'unique_visitors' => 300]],
                'os' => [['os' => 'Windows', 'visits' => 600, 'unique_visitors' => 250]],
                'devices' => [['device_type' => 'desktop', 'visits' => 700, 'unique_visitors' => 280]],
            ]);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/devices")
            ->assertOk()
            ->assertJsonStructure(['data' => ['browsers', 'os', 'devices']]);
    });
});

/*
|--------------------------------------------------------------------------
| Geo
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/geo', function () {

    it('returns geographic breakdown', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('geo')
            ->once()
            ->andReturn([
                'countries' => [['country' => 'US', 'pageviews' => 500, 'unique_visitors' => 200]],
                'regions' => [],
            ]);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/geo")
            ->assertOk()
            ->assertJsonStructure(['data' => ['countries', 'regions']]);
    });
});

/*
|--------------------------------------------------------------------------
| Custom events
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/custom-events', function () {

    it('returns custom event data', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('customEvents')
            ->once()
            ->andReturn([
                ['name' => 'signup', 'occurrences' => 42, 'unique_visitors' => 40],
            ]);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/custom-events")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'signup');
    });
});

/*
|--------------------------------------------------------------------------
| Realtime
|--------------------------------------------------------------------------
*/
describe('GET /api/domains/{domain}/analytics/realtime', function () {

    it('requires authentication', function () {
        $domain = analyticsDomain(analyticsUser());

        $this->getJson("/api/domains/{$domain->id}/analytics/realtime")
            ->assertStatus(401);
    });

    it('returns active visitor count from redis', function () {
        $user = analyticsUser();
        $domain = analyticsDomain($user);

        $this->mock(AnalyticsQueryService::class)
            ->shouldReceive('activeVisitors')
            ->once()
            ->with($domain->id)
            ->andReturn(7);

        $this->actingAs($user)
            ->getJson("/api/domains/{$domain->id}/analytics/realtime")
            ->assertOk()
            ->assertJsonPath('data.active_visitors', 7)
            ->assertJsonStructure(['data' => ['active_visitors', 'ts']]);
    });
});
