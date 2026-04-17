<?php

use App\Models\Domain;
use App\Models\User;
use App\Jobs\ProcessTrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

function activeDomain(): Domain
{
    $user = User::factory()->create(['status' => 'active']);
    return Domain::factory()->create(['user_id' => $user->id, 'active' => true]);
}

/*
|--------------------------------------------------------------------------
| Track endpoint
|--------------------------------------------------------------------------
*/

test('valid pageview is accepted and queued', function () {
    $domain = activeDomain();

    $this->postJson('/api/track', [
        't' => $domain->script_token,
        'e' => 'pageview',
        'u' => 'https://mysite.com/home',
        'r' => 'https://google.com',
        'pt' => 'Home',
        'sw' => 1920,
        'sh' => 1080,
    ])->assertStatus(204);

    Queue::assertPushedOn('tracking', ProcessTrackingEvent::class);
});

test('missing token returns 400', function () {
    $this->postJson('/api/track', ['e' => 'pageview'])
        ->assertStatus(400);

    Queue::assertNothingPushed();
});

test('unknown token returns 401', function () {
    $this->postJson('/api/track', [
        't' => 'totally-unknown-token',
        'e' => 'pageview',
        'u' => 'https://mysite.com/',
    ])->assertStatus(401);

    Queue::assertNothingPushed();
});

test('inactive domain returns 401', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->create(['user_id' => $user->id, 'active' => false]);

    $this->postJson('/api/track', [
        't' => $domain->script_token,
        'e' => 'pageview',
        'u' => 'https://mysite.com/',
    ])->assertStatus(401);

    Queue::assertNothingPushed();
});

test('grace-period previous token is accepted', function () {
    $domain = activeDomain();
    $old = $domain->script_token;
    $domain->rotateToken(); // moves script_token to previous_script_token, creates new one

    $this->postJson('/api/track', [
        't' => $old,
        'e' => 'pageview',
        'u' => 'https://mysite.com/',
    ])->assertStatus(204);

    Queue::assertPushedOn('tracking', ProcessTrackingEvent::class);
});

test('expired previous token is rejected', function () {
    $domain = activeDomain();
    $old = $domain->script_token;
    $domain->update([
        'previous_script_token' => $old,
        'script_token' => bin2hex(random_bytes(32)),
        'token_rotated_at' => now()->subMinutes(90), // past grace period
    ]);

    $this->postJson('/api/track', [
        't' => $old,
        'e' => 'pageview',
        'u' => 'https://mysite.com/',
    ])->assertStatus(401);
});

test('first hit marks script as verified in cache', function () {
    $domain = activeDomain();

    expect($domain->isScriptVerified())->toBeFalse();

    $this->postJson('/api/track', [
        't' => $domain->script_token,
        'e' => 'pageview',
        'u' => 'https://mysite.com/',
    ])->assertStatus(204);

    expect(cache()->has("script_verified:{$domain->script_token}"))->toBeTrue();
});

test('cors preflight returns 204 with correct headers', function () {
    $this->options('/api/track')
        ->assertStatus(204)
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
});

test('custom event is queued with props', function () {
    $domain = activeDomain();

    $this->postJson('/api/track', [
        't' => $domain->script_token,
        'e' => 'custom',
        'u' => 'https://mysite.com/',
        'p' => ['plan' => 'pro', 'source' => 'landing'],
    ])->assertStatus(204);

    Queue::assertPushedOn('tracking', ProcessTrackingEvent::class, function ($job) {
        return $job->payload['type'] === 'custom'
            && $job->payload['props']['plan'] === 'pro';
    });
});
