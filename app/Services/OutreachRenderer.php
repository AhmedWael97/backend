<?php

namespace App\Services;

/**
 * Turns an outreach email's plain-text body into the HTML that actually goes
 * out, including the required unsubscribe footer.
 *
 * Shared by OutreachController::send() and eye:preview-outreach so a preview
 * is byte-for-byte what the prospect would receive — a preview rendered by a
 * second code path is worth very little.
 */
class OutreachRenderer
{
    public static function html(string $body, string $unsubscribeUrl): string
    {
        return nl2br(e($body))
            . '<br><br><hr style="border:none;border-top:1px solid #eee">'
            . '<p style="font-size:12px;color:#888">' . e(config('app.name', 'EYE Analytics'))
            . ' — you received this as a business contact. '
            . '<a href="' . $unsubscribeUrl . '">Unsubscribe</a>.</p>';
    }
}
