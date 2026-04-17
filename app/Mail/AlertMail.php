<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EYE Alert: ' . ($this->data['title'] ?? 'Threshold breached'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.alert', with: [
            'name' => $this->recipient->name,
            'title' => $this->data['title'] ?? '',
            'body' => $this->data['body'] ?? '',
            'actionUrl' => $this->data['action_url'] ?? config('app.frontend_url') . '/dashboard',
            'unsubscribeUrl' => $this->unsubscribeUrl('alert'),
        ]);
    }
}
