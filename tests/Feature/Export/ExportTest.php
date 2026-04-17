<?php

use App\Models\Domain;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->domain = Domain::factory()->create(['user_id' => $this->user->id]);
});

test('can request an export', function () {
    Queue::fake();

    $response = $this->withToken($this->token)
        ->postJson('/api/exports', [
            'domain_id' => $this->domain->id,
            'type' => 'visitors',
            'format' => 'csv',
        ]);

    $response->assertCreated();
    expect(ExportJob::where('user_id', $this->user->id)->exists())->toBeTrue();
});

test('can list export jobs', function () {
    ExportJob::create([
        'user_id' => $this->user->id,
        'domain_id' => $this->domain->id,
        'type' => 'visitors',
        'format' => 'csv',
        'status' => 'pending',
    ]);

    $response = $this->withToken($this->token)->getJson('/api/exports');

    $response->assertOk()->assertJsonStructure(['data']);
});

test('export job is scoped to authenticated user', function () {
    $other = User::factory()->create();
    ExportJob::create([
        'user_id' => $other->id,
        'domain_id' => Domain::factory()->create(['user_id' => $other->id])->id,
        'type' => 'visitors',
        'format' => 'csv',
        'status' => 'done',
    ]);

    $response = $this->withToken($this->token)->getJson('/api/exports');

    $response->assertOk();
    // Our user should not see the other user's export
    $data = $response->json('data');
    foreach ($data as $item) {
        expect($item['user_id'])->toBe($this->user->id);
    }
});

test('cannot download export belonging to another user', function () {
    $other = User::factory()->create();
    $export = ExportJob::create([
        'user_id' => $other->id,
        'domain_id' => Domain::factory()->create(['user_id' => $other->id])->id,
        'type' => 'visitors',
        'format' => 'csv',
        'status' => 'done',
        'file_path' => 'exports/other_export.csv',
    ]);

    $response = $this->withToken($this->token)->getJson("/api/exports/{$export->id}/download");

    $response->assertStatus(403);
});
