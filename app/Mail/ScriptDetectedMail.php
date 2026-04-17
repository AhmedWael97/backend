<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ScriptDetectedMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EYE is now tracking ' . ($this->data['domain'] ?? 'your domain'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.script_detected', with: [
            'name' => $this->recipient->name,
            'domain' => $this->data['domain'] ?? '',
            'analyticsUrl' => config('app.frontend_url') . '/dashboard',
            'unsubscribeUrl' => $this->unsubscribeUrl('script_detected'),
        ]);
    }
}
