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

    public function __construct(
        public readonly User $recipient,
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
