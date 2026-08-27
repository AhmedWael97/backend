<?php

namespace App\Services;

/**
 * Renders an outreach email to the HTML that actually goes out.
 *
 * Two paths. When a draft carries structured `meta` (host, score, verified
 * issues, CTA link, pricing) it is laid out as a designed message: a score
 * badge, one card per finding, a real button. Anything else — hand-composed
 * mail, drafts written before meta existed — falls back to the plain-text
 * body run through nl2br.
 *
 * Built with tables and inline styles on purpose. Gmail strips <style> blocks
 * in several contexts and Outlook renders through Word, so a stylesheet or a
 * flex layout would come apart in exactly the inboxes this is aimed at.
 *
 * The footer carries the opt-out link and the sender's physical postal
 * address: CAN-SPAM requires both in every commercial email. See
 * config/outreach.php — the scheduled sender refuses to run without an address
 * rather than shipping non-compliant mail.
 */
class OutreachRenderer
{
    private const INK = '#111827';
    private const MUTED = '#6B7280';
    private const LINE = '#E5E7EB';
    private const ACCENT = '#0B7285';
    private const CANVAS = '#F3F4F6';

    /** @param array<string, mixed>|null $meta */
    public static function html(string $body, string $unsubscribeUrl, ?array $meta = null): string
    {
        $inner = $meta ? self::designed($body, $meta) : self::plain($body);

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="background:' . self::CANVAS . ';margin:0;padding:24px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"'
            . ' style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid ' . self::LINE . ';border-radius:10px">'
            . $inner
            . self::footer($unsubscribeUrl)
            . '</table></td></tr></table>';
    }

    /** @param array<string, mixed> $meta */
    private static function designed(string $body, array $meta): string
    {
        $host = (string) ($meta['host'] ?? '');
        $score = (int) ($meta['score'] ?? 0);
        $issues = (array) ($meta['issues'] ?? []);
        $scanUrl = (string) ($meta['scan_url'] ?? '');
        $pricing = (string) ($meta['pricing'] ?? '');
        $issueCount = (int) ($meta['issue_count'] ?? count($issues));

        // Red below 60, amber below 80, green above — the same reading the
        // dashboard gives the number, so the email cannot contradict the app.
        $tone = $score >= 80 ? '#047857' : ($score >= 60 ? '#B45309' : '#B91C1C');

        $html = '<tr><td style="padding:28px 32px 0">'
            . '<div style="font:600 13px/1 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'letter-spacing:.14em;text-transform:uppercase;color:' . self::MUTED . '">EYE Analytics</div>'
            . '<h1 style="margin:14px 0 0;font:700 22px/1.3 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'color:' . self::INK . '">We checked ' . e($host) . '</h1>'
            . '</td></tr>';

        // Score + count, side by side.
        $html .= '<tr><td style="padding:20px 32px 0">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr>'
            . '<td width="50%" style="padding:14px 16px;background:' . self::CANVAS . ';border-radius:8px" valign="top">'
            . '<div style="font:700 30px/1 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . $tone . '">'
            . $score . '<span style="font-size:15px;color:' . self::MUTED . '">/100</span></div>'
            . '<div style="margin-top:6px;font:400 12px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'color:' . self::MUTED . '">Technical score</div>'
            . '</td>'
            . '<td width="12"></td>'
            . '<td width="50%" style="padding:14px 16px;background:' . self::CANVAS . ';border-radius:8px" valign="top">'
            . '<div style="font:700 30px/1 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK . '">'
            . $issueCount . '</div>'
            . '<div style="margin-top:6px;font:400 12px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'color:' . self::MUTED . '">' . ($issueCount === 1 ? 'Issue found' : 'Issues found') . '</div>'
            . '</td>'
            . '</tr></table></td></tr>';

        // One card per verified finding.
        if ($issues !== []) {
            $html .= '<tr><td style="padding:24px 32px 0">';
            foreach ($issues as $issue) {
                $label = (string) ($issue['label'] ?? '');
                $message = (string) ($issue['message'] ?? '');
                $fix = (string) ($issue['suggestion'] ?? '');
                $severity = (string) ($issue['severity'] ?? '');
                $bar = $severity === 'high' ? '#B91C1C' : '#B45309';

                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
                    . ' style="margin-bottom:10px"><tr>'
                    . '<td width="3" style="background:' . $bar . ';border-radius:2px"></td>'
                    . '<td style="padding:10px 0 10px 14px">'
                    . '<div style="font:600 14px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
                    . 'color:' . self::INK . '">' . e($label) . '</div>'
                    . '<div style="margin-top:3px;font:400 13px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
                    . 'color:#374151">' . e($message) . '</div>';

                if ($fix !== '') {
                    $html .= '<div style="margin-top:5px;font:400 13px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
                        . 'color:' . self::MUTED . '"><strong style="color:#374151">Fix:</strong> ' . e($fix) . '</div>';
                }

                $html .= '</td></tr></table>';
            }
            $html .= '</td></tr>';
        }

        if ($scanUrl !== '') {
            $html .= '<tr><td style="padding:18px 32px 0">'
                . '<a href="' . e($scanUrl) . '" style="display:inline-block;background:' . self::ACCENT . ';color:#FFFFFF;'
                . 'text-decoration:none;padding:13px 22px;border-radius:7px;'
                . 'font:600 15px/1 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
                . 'Check any client site &rarr;</a>'
                . '<div style="margin-top:9px;font:400 12px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
                . 'color:' . self::MUTED . '">Free, no account needed.</div>'
                . '</td></tr>';
        }

        $html .= '<tr><td style="padding:22px 32px 0">'
            . '<div style="font:400 14px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#374151">'
            . 'We build EYE Analytics — privacy-first analytics with heatmaps, session replay and campaign ROAS, '
            . 'for watching every client site from one dashboard.</div>';

        if ($pricing !== '') {
            $html .= '<div style="margin-top:12px;padding:12px 15px;border:1px solid ' . self::LINE . ';border-radius:8px;'
                . 'font:400 13px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK . '">'
                . e($pricing) . '</div>';
        }

        $html .= '</td></tr>';

        $html .= '<tr><td style="padding:20px 32px 30px">'
            . '<div style="font:400 14px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#374151">'
            . 'Reply if you would like the full report for ' . e($host) . '.</div>'
            . '<div style="margin-top:14px;font:400 14px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'color:' . self::MUTED . '">— The EYE Analytics team</div>'
            . '</td></tr>';

        return $html;
    }

    private static function plain(string $body): string
    {
        return '<tr><td style="padding:30px 32px;'
            . 'font:400 14px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK . '">'
            . nl2br(e($body))
            . '</td></tr>';
    }

    private static function footer(string $unsubscribeUrl): string
    {
        $footer = e(config('app.name', 'EYE Analytics'))
            . ' — you received this as a business contact. '
            . '<a href="' . $unsubscribeUrl . '" style="color:' . self::MUTED . '">Unsubscribe</a>.';

        if ($address = trim((string) config('outreach.postal_address'))) {
            $footer .= '<br>' . e($address);
        }

        return '<tr><td style="padding:0 32px 26px">'
            . '<div style="border-top:1px solid ' . self::LINE . ';padding-top:16px;'
            . 'font:400 12px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::MUTED . '">'
            . $footer . '</div></td></tr>';
    }
}
