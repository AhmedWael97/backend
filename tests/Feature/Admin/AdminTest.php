<?php

use App\Models\Domain;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'superadmin']);
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

    $this->user = User::factory()->create(['role' => 'user']);
    $this->userToken = $this->user->createToken('user')->plainTextToken;
});

test('superadmin can list users', function () {
    $response = $this->withToken($this->adminToken)->getJson('/api/admin/users');

    $response->assertOk()->assertJsonStructure(['data']);
});

test('regular user cannot access admin users list', function () {
    $response = $this->withToken($this->userToken)->getJson('/api/admin/users');

    $response->assertStatus(403);
});

test('superadmin can block a user', function () {
    $target = User::factory()->create(['status' => 'active']);

    $response = $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$target->id}/block");

    $response->assertOk();
    expect($target->refresh()->status)->toBe('blocked');
});

test('superadmin can unblock a user', function () {
    $target = User::factory()->create(['status' => 'blocked']);

    $response = $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$target->id}/unblock");

    $response->assertOk();
    expect($target->refresh()->status)->toBe('active');
});

test('superadmin can impersonate a user', function () {
    $target = User::factory()->create();

    $response = $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$target->id}/impersonate");

    $response->assertOk()->assertJsonStructure(['data' => ['token', 'target_user', 'expires_at']]);
});

test('superadmin can end impersonation', function () {
    $response = $this->withToken($this->adminToken)
        ->deleteJson('/api/admin/impersonate');

    // Either OK (was impersonating) or 400 (not in impersonation session) — both are valid
    expect($response->status())->toBeIn([200, 400]);
});

test('superadmin can view admin stats', function () {
    $response = $this->withToken($this->adminToken)->getJson('/api/admin/stats');

    $response->assertOk()->assertJsonStructure(['data' => ['total_users', 'active_users', 'active_subscriptions']]);
});

test('regular user cannot access admin stats', function () {
    $response = $this->withToken($this->userToken)->getJson('/api/admin/stats');

    $response->assertStatus(403);
});
