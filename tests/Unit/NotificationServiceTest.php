<?php

use App\Events\NotificationCreatedEvent;
use App\Mail\AlertMail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// Helper: create a user with a specific preference row
function makeUserWithPref(string $type, bool $inApp, bool $email): User
{
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'type' => $type,
        'in_app' => $inApp,
        'email' => $email,
    ]);
    return $user;
}

test('sends in-app notification when in_app is true', function () {
    Event::fake([NotificationCreatedEvent::class]);
    Mail::fake();

    $user = makeUserWithPref('alert', inApp: true, email: false);
    $service = new NotificationService();
    $service->send($user, 'alert', ['title' => 'Test Alert', 'body' => 'body text']);

    expect(Notification::where('user_id', $user->id)->where('type', 'alert')->count())->toBe(1);
    Event::assertDispatched(NotificationCreatedEvent::class);
    Mail::assertNothingSent();
});

test('sends email when email is true', function () {
    Event::fake([NotificationCreatedEvent::class]);
    Mail::fake();

    $user = makeUserWithPref('alert', inApp: false, email: true);
    $service = new NotificationService();
    $service->send($user, 'alert', ['title' => 'Alert Title', 'body' => 'alert body']);

    Mail::assertQueued(AlertMail::class, fn($mail) => $mail->hasTo($user->email));
    expect(Notification::where('user_id', $user->id)->count())->toBe(0);
});

test('sends both in-app and email when both flags are true', function () {
    Event::fake([NotificationCreatedEvent::class]);
    Mail::fake();

    $user = makeUserWithPref('alert', inApp: true, email: true);
    $service = new NotificationService();
    $service->send($user, 'alert', ['title' => 'Both', 'body' => 'body']);

    expect(Notification::where('user_id', $user->id)->count())->toBe(1);
    Mail::assertQueued(AlertMail::class);
    Event::assertDispatched(NotificationCreatedEvent::class);
});

test('sends nothing when both flags are false', function () {
    Event::fake([NotificationCreatedEvent::class]);
    Mail::fake();

    $user = makeUserWithPref('alert', inApp: false, email: false);
    $service = new NotificationService();
    $service->send($user, 'alert', ['title' => 'Muted', 'body' => 'body']);

    expect(Notification::where('user_id', $user->id)->count())->toBe(0);
    Mail::assertNothingSent();
    Event::assertNotDispatched(NotificationCreatedEvent::class);
});

test('defaults to in_app=true email=true when no preference row exists', function () {
    Event::fake([NotificationCreatedEvent::class]);
    Mail::fake();

    $user = User::factory()->create();
    // No preference row — service should fall back to defaults
    $service = new NotificationService();
    $service->send($user, 'alert', ['title' => 'Default', 'body' => 'body']);

    expect(Notification::where('user_id', $user->id)->count())->toBe(1);
    Mail::assertQueued(AlertMail::class);
});

test('sets email_sent_at on the notification row after email dispatch', function () {
    Event::fake([NotificationCreatedEvent::class]);
    Mail::fake();

    $user = makeUserWithPref('alert', inApp: true, email: true);
    $service = new NotificationService();
    $service->send($user, 'alert', ['title' => 'Sent At', 'body' => 'body']);

    $notification = Notification::where('user_id', $user->id)->first();
    expect($notification->email_sent_at)->not->toBeNull();
});
