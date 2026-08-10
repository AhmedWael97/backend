<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

abstract class BaseNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    // Not readonly: SerializesModels rewrites every public constructor-
    // promoted property (not just the Eloquent model ones) when a queued
    // Mailable is unserialized on the worker — a readonly property can only
    // be initialized once, from the declaring class, so that second write
    // throws "Cannot initialize readonly property" and the job dies before
    // ever sending. Confirmed via serialize()/unserialize() round-trip.
    public function __construct(
        public User $recipient,
        public array $data = [],
    ) {
    }

    protected function unsubscribeUrl(string $type): string
    {
        return url()->signedRoute('notifications.unsubscribe', [
            'user' => $this->recipient->id,
            'type' => $type,
        ], now()->addDays(30));
    }
}
