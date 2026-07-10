<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrialEndingMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        $locale = $this->recipient->locale ?? 'en';
        $days = (int) ($this->data['days_left'] ?? 5);
        $subject = $locale === 'ar'
            ? "تجربتك المجانية تنتهي خلال {$days} أيام"
            : "Your trial ends in {$days} days";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial_ending', with: [
            'name' => $this->recipient->name,
            'daysLeft' => (int) ($this->data['days_left'] ?? 5),
            'visitors' => (int) ($this->data['visitors'] ?? 0),
            'billingUrl' => config('app.frontend_url') . '/settings/billing?trial=ending',
            'unsubscribeUrl' => $this->unsubscribeUrl('trial_ending'),
        ]);
    }
}
