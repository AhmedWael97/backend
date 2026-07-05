<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One branded, spam-safe Mailable reused by every transactional/broadcast email:
 * consistent from/reply-to (info@eye-analysis.online), HTML + plain-text parts,
 * and an unsubscribe link. Pass structured `lines` or a `rawHtml` body.
 */
class BrandedEmail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string,mixed> $data heading, lines[]|rawHtml, ctaText, ctaUrl, preheader, replyNote, unsubUrl */
    public function __construct(public string $subjectLine, public array $data)
    {
    }

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address', 'info@eye-analysis.online');
        return new Envelope(
            from: new Address($from, (string) config('mail.from.name', 'EYE Analytics')),
            replyTo: [new Address($from, 'EYE Analytics')],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.message',
            text: 'emails.message_text',
            with: $this->data,
        );
    }
}
