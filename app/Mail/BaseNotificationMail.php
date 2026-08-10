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

    // Not readonly: SerializesModels re-hydrates the $recipient model on the
    // queue worker by writing to this property from its own scope after
    // construction (queued mail is serialized then unserialized) — a
    // readonly property can only be initialized once, from the declaring
    // class, so that second write throws "Cannot initialize readonly
    // property" and the job dies before ever sending.
    public function __construct(
        public User $recipient,
        public readonly array $data = [],
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
