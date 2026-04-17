<?php

use App\Models\Domain;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
});

test('can list notifications', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'alert',
        'title' => 'Alert 1',
        'body' => 'body',
        'channel' => 'in_app',
    ]);

    $response = $this->withToken($this->token)->getJson('/api/notifications');

    $response->assertOk()->assertJsonStructure(['data']);
});

test('can mark a notification as read', function () {
    $notification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'alert',
        'title' => 'Unread',
        'body' => 'body',
        'channel' => 'in_app',
    ]);

    $response = $this->withToken($this->token)
        ->patchJson("/api/notifications/{$notification->id}/read");

    $response->assertOk();
    expect($notification->refresh()->read_at)->not->toBeNull();
});

test('can mark all notifications as read', function () {
    Notification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'N1', 'body' => '', 'channel' => 'in_app']);
    Notification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'N2', 'body' => '', 'channel' => 'in_app']);

    $response = $this->withToken($this->token)->patchJson('/api/notifications/read-all');

    $response->assertOk();
    expect(Notification::where('user_id', $this->user->id)->whereNull('read_at')->count())->toBe(0);
});

test('can delete a notification', function () {
    $notification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'alert',
        'title' => 'ToDelete',
        'body' => 'body',
        'channel' => 'in_app',
    ]);

    $response = $this->withToken($this->token)->deleteJson("/api/notifications/{$notification->id}");

    $response->assertOk();
    expect(Notification::find($notification->id))->toBeNull();
});

test('can get notification preferences', function () {
    NotificationPreference::create(['user_id' => $this->user->id, 'type' => 'alert', 'in_app' => true, 'email' => true]);

    $response = $this->withToken($this->token)->getJson('/api/notification-preferences');

    $response->assertOk()->assertJsonStructure(['data']);
});

test('can update notification preferences', function () {
    NotificationPreference::create(['user_id' => $this->user->id, 'type' => 'alert', 'in_app' => true, 'email' => true]);

    $response = $this->withToken($this->token)->patchJson('/api/notification-preferences', [
        'preferences' => [['type' => 'alert', 'in_app' => false, 'email' => false]],
    ]);

    $response->assertOk();
    $pref = NotificationPreference::where('user_id', $this->user->id)->where('type', 'alert')->first();
    expect((bool) $pref->in_app)->toBeFalse();
    expect((bool) $pref->email)->toBeFalse();
});

test('cannot access another users notifications', function () {
    $other = User::factory()->create();
    $notification = Notification::create([
        'user_id' => $other->id,
        'type' => 'alert',
        'title' => 'Other',
        'body' => 'body',
        'channel' => 'in_app',
    ]);

    $response = $this->withToken($this->token)->deleteJson("/api/notifications/{$notification->id}");

    $response->assertStatus(404);
});
