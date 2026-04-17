<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklyDigestMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        $locale = $this->recipient->locale ?? 'en';
        $subject = $locale === 'ar'
            ? 'ملخص EYE الأسبوعي'
            : 'Your Weekly EYE Analytics Digest';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.weekly_digest', with: [
            'name' => $this->recipient->name,
            'visitors' => $this->data['visitors'] ?? 0,
            'sessions' => $this->data['sessions'] ?? 0,
            'topPage' => $this->data['top_page'] ?? '',
            'topCountry' => $this->data['top_country'] ?? '',
            'scoreDelta' => $this->data['score_delta'] ?? null,
            'dashboardUrl' => config('app.frontend_url') . '/dashboard',
            'unsubscribeUrl' => $this->unsubscribeUrl('weekly_digest'),
        ]);
    }
}
