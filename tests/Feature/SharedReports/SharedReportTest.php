<?php

use App\Models\Domain;
use App\Models\SharedReport;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->domain = Domain::factory()->create(['user_id' => $this->user->id]);
});

test('can create a shared report link', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/shared-reports', [
            'domain_id' => $this->domain->id,
            'label' => 'Monthly Overview',
        ]);

    $response->assertCreated();
    $report = SharedReport::where('domain_id', $this->domain->id)->first();
    expect($report)->not->toBeNull();
    expect($report->token)->not->toBeEmpty();
});

test('public report endpoint returns 200 for valid token', function () {
    $report = SharedReport::create([
        'domain_id' => $this->domain->id,
        'user_id' => $this->user->id,
        'label' => 'Public',
    ]);

    $response = $this->getJson("/api/public/report/{$report->token}");

    $response->assertOk();
});

test('public report endpoint returns 404 for invalid token', function () {
    $response = $this->getJson('/api/public/report/this-token-does-not-exist');

    $response->assertNotFound();
});

test('public report endpoint returns 410 or 404 for expired token', function () {
    $report = SharedReport::create([
        'domain_id' => $this->domain->id,
        'user_id' => $this->user->id,
        'label' => 'Expired',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->getJson("/api/public/report/{$report->token}");

    $response->assertStatus(in_array($response->status(), [404, 410]) ? $response->status() : 410);
});

test('can list shared reports', function () {
    SharedReport::create([
        'domain_id' => $this->domain->id,
        'user_id' => $this->user->id,
        'label' => 'Listed',
    ]);

    $response = $this->withToken($this->token)->getJson('/api/shared-reports');

    $response->assertOk()->assertJsonStructure(['data']);
});

test('can delete a shared report', function () {
    $report = SharedReport::create([
        'domain_id' => $this->domain->id,
        'user_id' => $this->user->id,
        'label' => 'ToDelete',
    ]);

    $response = $this->withToken($this->token)->deleteJson("/api/shared-reports/{$report->id}");

    $response->assertOk();
    expect(SharedReport::find($report->id))->toBeNull();
});
