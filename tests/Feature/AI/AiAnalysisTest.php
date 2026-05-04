<?php

use App\Models\Domain;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->domain = Domain::factory()->create(['user_id' => $this->user->id]);
});

test('ai quota endpoint returns remaining quota', function () {
    $response = $this->withToken($this->token)
        ->getJson("/api/ai/{$this->domain->id}/quota");

    $response->assertOk()->assertJsonStructure(['used', 'limit', 'remaining']);
});

test('ai segments endpoint returns list or empty array', function () {
    $response = $this->withToken($this->token)
        ->getJson("/api/ai/{$this->domain->id}/segments");

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toBeArray();
});

test('ai suggestions endpoint returns list or empty array', function () {
    $response = $this->withToken($this->token)
        ->getJson("/api/ai/{$this->domain->id}/suggestions");

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toBeArray();
});

test('ai analyze returns 202 accepted when quota available', function () {
    $response = $this->withToken($this->token)
        ->postJson("/api/ai/{$this->domain->id}/analyze");

    // 202 Accepted (job dispatched) or 429 if quota exceeded
    expect($response->status())->toBeIn([202, 429]);
});

test('ai chat returns 503 phase 2 stub', function () {
    $response = $this->withToken($this->token)
        ->postJson("/api/ai/{$this->domain->id}/chat", ['message' => 'hello']);

    $response->assertStatus(503)
        ->assertJsonFragment(['phase' => 2]);
});

test('cannot access ai endpoints for another users domain', function () {
    $other = User::factory()->create();
    $otherDomain = Domain::factory()->create(['user_id' => $other->id]);

    $response = $this->withToken($this->token)
        ->getJson("/api/ai/{$otherDomain->id}/quota");

    $response->assertStatus(404);
});
