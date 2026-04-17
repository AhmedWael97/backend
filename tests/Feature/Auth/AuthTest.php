<?php

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Plan::factory()->create(['slug' => 'free', 'price_monthly' => 0, 'is_active' => true]);
});

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

test('user can register with valid data', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

test('registration fails with duplicate email', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->postJson('/api/auth/register', [
        'name' => 'Dup',
        'email' => 'dup@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
    ])->assertStatus(422);
});

test('registration fails with short password', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Short',
        'email' => 'short@example.com',
        'password' => '123',
        'password_confirmation' => '123',
    ])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

test('user can login with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'login@example.com',
        'password' => bcrypt('Password1'),
        'status' => 'active',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'login@example.com',
        'password' => 'Password1',
    ])->assertOk()->assertJsonStructure(['user', 'token']);
});

test('login fails with wrong password', function () {
    User::factory()->create([
        'email' => 'wrong@example.com',
        'password' => bcrypt('correct'),
        'status' => 'active',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'wrong@example.com',
        'password' => 'wrongpass',
    ])->assertStatus(422);
});

test('suspended user cannot login', function () {
    User::factory()->create([
        'email' => 'suspended@example.com',
        'password' => bcrypt('Password1'),
        'status' => 'suspended',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'suspended@example.com',
        'password' => 'Password1',
    ])->assertStatus(403);
});

test('login returns two_factor flag when 2fa is enabled', function () {
    User::factory()->create([
        'email' => 'totp@example.com',
        'password' => bcrypt('Password1'),
        'status' => 'active',
        'totp_enabled' => true,
        'totp_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'totp@example.com',
        'password' => 'Password1',
    ])->assertOk()->assertJsonFragment(['two_factor' => true]);
});

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/

test('authenticated user can logout', function () {
    $user = User::factory()->create(['status' => 'active']);
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonFragment(['message' => 'Logged out.']);
});

/*
|--------------------------------------------------------------------------
| Profile
|--------------------------------------------------------------------------
*/

test('authenticated user can view profile', function () {
    $user = User::factory()->create(['status' => 'active']);
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/profile')
        ->assertOk()
        ->assertJsonStructure(['user' => ['id', 'name', 'email']]);
});

test('unauthenticated request is rejected', function () {
    $this->getJson('/api/profile')->assertStatus(401);
});

test('user can update profile', function () {
    $user = User::factory()->create(['status' => 'active', 'locale' => 'en']);
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/profile', ['locale' => 'ar', 'appearance' => 'dark'])
        ->assertOk()
        ->assertJsonPath('user.locale', 'ar');
});
