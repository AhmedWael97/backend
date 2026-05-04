<?php

use App\Models\Domain;
use App\Models\User;
use App\Models\Webhook;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->domain = Domain::factory()->create(['user_id' => $this->user->id]);
});

test('can list webhooks for a domain', function () {
    Webhook::create([
        'domain_id' => $this->domain->id,
        'url' => 'https://example.com/hook',
        'events' => ['pageview'],
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/domains/{$this->domain->id}/webhooks");

    $response->assertOk()->assertJsonStructure(['data']);
});

test('can create a webhook', function () {
    $response = $this->withToken($this->token)
        ->postJson("/api/domains/{$this->domain->id}/webhooks", [
            'url' => 'https://hooks.example.com/receive',
            'events' => ['pageview', 'custom_event'],
            'is_active' => true,
        ]);

    $response->assertCreated();
    $webhook = Webhook::where('domain_id', $this->domain->id)->first();
    expect($webhook)->not->toBeNull();
    expect($webhook->url)->toBe('https://hooks.example.com/receive');
    // Secret auto-generated
    expect($webhook->secret)->not->toBeEmpty();
});

test('webhook secret is auto-generated on creation', function () {
    $response = $this->withToken($this->token)
        ->postJson("/api/domains/{$this->domain->id}/webhooks", [
            'url' => 'https://hooks.example.com/auto',
            'events' => ['pageview'],
        ]);

    $response->assertCreated();
    $webhook = Webhook::where('domain_id', $this->domain->id)->first();
    expect(strlen($webhook->secret))->toBe(64); // bin2hex(32 bytes) = 64 hex chars
});

test('can update a webhook', function () {
    $webhook = Webhook::create([
        'domain_id' => $this->domain->id,
        'url' => 'https://old.example.com/hook',
        'events' => ['pageview'],
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->putJson("/api/domains/{$this->domain->id}/webhooks/{$webhook->id}", [
            'url' => 'https://new.example.com/hook',
            'events' => ['pageview', 'session_end'],
            'is_active' => false,
        ]);

    $response->assertOk();
    expect($webhook->refresh()->url)->toBe('https://new.example.com/hook');
    expect($webhook->refresh()->is_active)->toBeFalse();
});

test('can delete a webhook', function () {
    $webhook = Webhook::create([
        'domain_id' => $this->domain->id,
        'url' => 'https://delete.example.com/hook',
        'events' => ['pageview'],
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson("/api/domains/{$this->domain->id}/webhooks/{$webhook->id}");

    $response->assertOk();
    expect(Webhook::find($webhook->id))->toBeNull();
});

test('cannot manage webhooks for another users domain', function () {
    $other = User::factory()->create();
    $otherDomain = Domain::factory()->create(['user_id' => $other->id]);

    $response = $this->withToken($this->token)
        ->postJson("/api/domains/{$otherDomain->id}/webhooks", [
            'url' => 'https://evil.example.com/hook',
            'events' => ['pageview'],
        ]);

    $response->assertStatus(404);
});
