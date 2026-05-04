<?php

use App\Models\Domain;
use App\Models\DomainExclusion;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function userWithPlan(int $domainLimit = 5): array
{
    $user = User::factory()->create(['status' => 'active']);
    $plan = Plan::factory()->create([
        'slug' => 'pro',
        'limits' => ['domains' => $domainLimit, 'events_per_day' => 100000, 'retention_days' => 90],
    ]);
    Subscription::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'current_period_start' => now(),
    ]);
    $token = $user->createToken('api')->plainTextToken;

    return [$user, $token];
}

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

test('user can list their domains', function () {
    [$user, $token] = userWithPlan();
    Domain::factory()->count(3)->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->getJson('/api/domains')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can create a domain', function () {
    [, $token] = userWithPlan();

    $this->withToken($token)
        ->postJson('/api/domains', ['domain' => 'example.com'])
        ->assertStatus(201)
        ->assertJsonPath('data.domain', 'example.com')
        ->assertJsonStructure(['data' => ['id', 'script_token']]);
});

test('domain is normalised to lowercase', function () {
    [, $token] = userWithPlan();

    $this->withToken($token)
        ->postJson('/api/domains', ['domain' => 'MySite.COM'])
        ->assertStatus(201)
        ->assertJsonPath('data.domain', 'mysite.com');
});

test('duplicate domain for same user is rejected', function () {
    [$user, $token] = userWithPlan();
    Domain::factory()->create(['user_id' => $user->id, 'domain' => 'dup.com']);

    $this->withToken($token)
        ->postJson('/api/domains', ['domain' => 'dup.com'])
        ->assertStatus(422);
});

test('domain limit is enforced', function () {
    [$user, $token] = userWithPlan(domainLimit: 1);
    Domain::factory()->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->postJson('/api/domains', ['domain' => 'second.com'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Your plan allows up to 1 domain(s). Please upgrade to add more.']);
});

test('user can update domain settings', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->patchJson("/api/domains/{$domain->id}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);
});

test('user cannot access another user\'s domain', function () {
    [, $token] = userWithPlan();
    $other = User::factory()->create();
    $domain = Domain::factory()->create(['user_id' => $other->id]);

    $this->withToken($token)
        ->getJson("/api/domains/{$domain->id}")
        ->assertStatus(404);
});

test('user can delete their domain', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->deleteJson("/api/domains/{$domain->id}")
        ->assertOk();

    $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
});

/*
|--------------------------------------------------------------------------
| Token rotation
|--------------------------------------------------------------------------
*/

test('user can rotate script token', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);
    $oldToken = $domain->script_token;

    $this->withToken($token)
        ->postJson("/api/domains/{$domain->id}/rotate-token")
        ->assertOk()
        ->assertJsonStructure(['data' => ['script_token', 'previous_script_token', 'token_rotated_at']]);

    $domain->refresh();
    expect($domain->script_token)->not->toBe($oldToken);
    expect($domain->previous_script_token)->toBe($oldToken);
});

/*
|--------------------------------------------------------------------------
| Script verification
|--------------------------------------------------------------------------
*/

test('verify-script returns false when no beacon received', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->postJson("/api/domains/{$domain->id}/verify-script")
        ->assertOk()
        ->assertJsonPath('data.verified', false);
});

test('verify-script returns true when cache beacon present', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);

    // Simulate tracker writing beacon
    cache()->put("script_verified:{$domain->script_token}", true, 600);

    $this->withToken($token)
        ->postJson("/api/domains/{$domain->id}/verify-script")
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    $this->assertDatabaseHas('domains', [
        'id' => $domain->id,
        'script_verified_at' => now()->toDateTimeString(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Snippet
|--------------------------------------------------------------------------
*/

test('snippet endpoint returns html with token', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);

    $response = $this->withToken($token)
        ->get("/api/domains/{$domain->id}/snippet");

    $response->assertOk()
        ->assertSee($domain->script_token)
        ->assertSee('eye.min.js');
});

/*
|--------------------------------------------------------------------------
| Exclusions
|--------------------------------------------------------------------------
*/

test('user can add an ip exclusion', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->postJson("/api/domains/{$domain->id}/exclusions", [
            'type' => 'ip',
            'value' => '192.168.1.1',
        ])
        ->assertStatus(201)
        ->assertJsonFragment(['type' => 'ip', 'value' => '192.168.1.1']);
});

test('user can list exclusions', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);
    DomainExclusion::factory()->count(2)->create(['domain_id' => $domain->id]);

    $this->withToken($token)
        ->getJson("/api/domains/{$domain->id}/exclusions")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can delete an exclusion', function () {
    [$user, $token] = userWithPlan();
    $domain = Domain::factory()->create(['user_id' => $user->id]);
    $exclusion = DomainExclusion::factory()->create(['domain_id' => $domain->id]);

    $this->withToken($token)
        ->deleteJson("/api/domains/{$domain->id}/exclusions/{$exclusion->id}")
        ->assertOk();

    $this->assertDatabaseMissing('domain_exclusions', ['id' => $exclusion->id]);
});
