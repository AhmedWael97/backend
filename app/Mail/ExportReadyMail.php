<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ExportReadyMail extends BaseNotificationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your EYE Export is Ready');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.export_ready', with: [
            'name' => $this->recipient->name,
            'exportType' => $this->data['type'] ?? '',
            'format' => $this->data['format'] ?? '',
            'downloadUrl' => $this->data['download_url'] ?? '',
            'expiresIn' => '24 hours',
            'unsubscribeUrl' => $this->unsubscribeUrl('export_ready'),
        ]);
    }
}
