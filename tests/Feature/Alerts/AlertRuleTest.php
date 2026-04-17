<?php

use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->domain = Domain::factory()->create(['user_id' => $this->user->id]);
});

test('can list alert rules for a domain', function () {
    AlertRule::create([
        'domain_id' => $this->domain->id,
        'type' => 'traffic_drop',
        'threshold' => ['value' => 50, 'unit' => 'percent'],
        'channel' => 'in_app',
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/domains/{$this->domain->id}/alert-rules");

    $response->assertOk()->assertJsonStructure(['data']);
});

test('can create an alert rule', function () {
    $response = $this->withToken($this->token)
        ->postJson("/api/domains/{$this->domain->id}/alert-rules", [
            'type' => 'error_spike',
            'threshold' => ['value' => 10],
            'channel' => 'both',
            'is_active' => true,
        ]);

    $response->assertCreated();
    expect(AlertRule::where('domain_id', $this->domain->id)->where('type', 'error_spike')->exists())->toBeTrue();
});

test('can update an alert rule', function () {
    $rule = AlertRule::create([
        'domain_id' => $this->domain->id,
        'type' => 'traffic_drop',
        'threshold' => ['value' => 50],
        'channel' => 'in_app',
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->putJson("/api/domains/{$this->domain->id}/alert-rules/{$rule->id}", [
            'type' => 'traffic_drop',
            'threshold' => ['value' => 75],
            'channel' => 'email',
            'is_active' => false,
        ]);

    $response->assertOk();
    expect($rule->refresh()->threshold['value'])->toBe(75);
});

test('can delete an alert rule', function () {
    $rule = AlertRule::create([
        'domain_id' => $this->domain->id,
        'type' => 'quota_warning',
        'threshold' => ['value' => 80],
        'channel' => 'both',
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson("/api/domains/{$this->domain->id}/alert-rules/{$rule->id}");

    $response->assertOk();
    expect(AlertRule::find($rule->id))->toBeNull();
});

test('cannot manage alert rules for another users domain', function () {
    $other = User::factory()->create();
    $otherDomain = Domain::factory()->create(['user_id' => $other->id]);

    $response = $this->withToken($this->token)
        ->postJson("/api/domains/{$otherDomain->id}/alert-rules", [
            'type' => 'traffic_drop',
            'threshold' => ['value' => 50],
            'channel' => 'in_app',
        ]);

    $response->assertStatus(403);
});
