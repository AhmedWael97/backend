<?php

namespace App\Services;

/**
 * Turns an outreach email's plain-text body into the HTML that actually goes
 * out, including the footer every commercial message is required to carry.
 *
 * Shared by OutreachController::send() and the send/preview commands so a
 * preview is byte-for-byte what the prospect would receive — a preview
 * rendered by a second code path is worth very little.
 *
 * The footer carries the opt-out link and, when configured, the sender's
 * physical postal address: CAN-SPAM requires both in every commercial email.
 * See config/outreach.php — the scheduled sender refuses to run without an
 * address rather than shipping non-compliant mail.
 */
class OutreachRenderer
{
    public static function html(string $body, string $unsubscribeUrl): string
    {
        $footer = e(config('app.name', 'EYE Analytics'))
            . ' — you received this as a business contact. '
            . '<a href="' . $unsubscribeUrl . '">Unsubscribe</a>.';

        if ($address = trim((string) config('outreach.postal_address'))) {
            $footer .= '<br>' . e($address);
        }

        return nl2br(e($body))
            . '<br><br><hr style="border:none;border-top:1px solid #eee">'
            . '<p style="font-size:12px;color:#888">' . $footer . '</p>';
    }
}
