<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to EYE Analytics!');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome', with: [
            'name' => $this->recipient->name,
            'dashboardUrl' => config('app.frontend_url') . '/dashboard',
            'unsubscribeUrl' => $this->unsubscribeUrl('welcome'),
        ]);
    }
}
