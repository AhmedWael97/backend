<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class QuotaWarningMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EYE: You\'ve used ' . ($this->data['percent'] ?? '80') . '% of your event quota');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.quota_warning', with: [
            'name' => $this->recipient->name,
            'domain' => $this->data['domain'] ?? '',
            'percent' => $this->data['percent'] ?? 80,
            'upgradeUrl' => config('app.frontend_url') . '/settings/billing',
            'unsubscribeUrl' => $this->unsubscribeUrl('quota_warning'),
        ]);
    }
}
