<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubscriptionChangedMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your EYE Subscription Has Changed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription_changed', with: [
            'name' => $this->recipient->name,
            'oldPlan' => $this->data['old_plan'] ?? '',
            'newPlan' => $this->data['new_plan'] ?? '',
            'effectiveDate' => $this->data['effective_date'] ?? now()->format('Y-m-d'),
            'billingUrl' => config('app.frontend_url') . '/settings/billing',
            'unsubscribeUrl' => $this->unsubscribeUrl('subscription_changed'),
        ]);
    }
}
