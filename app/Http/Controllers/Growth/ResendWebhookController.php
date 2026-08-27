<?php

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\OutreachEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Resend webhooks: delivery outcomes and inbound replies.
 *
 * Delivery events (bounced / complained) feed the suppression list, the same
 * way the Mailgun hook does. Inbound mail is the interesting half: when a
 * prospect answers an outreach email, the matching lead flips to `replied` on
 * its own, so the Leads page shows what the campaign is actually producing
 * without anyone copying statuses across by hand.
 *
 * Two things are deliberately not treated as replies. Auto-responders (out of
 * office, ticket acknowledgements) would inflate the reply rate into a number
 * that means nothing — the one metric that tells you whether the offer works.
 * And a reply asking to stop is an opt-out first: it goes to the suppression
 * list and marks the lead lost, never `replied`.
 *
 * Public route, so authenticity rests entirely on the Svix signature Resend
 * sends. Without a configured secret the endpoint refuses everything rather
 * than trusting the body.
 */
class ResendWebhookController extends Controller
{
    /** Reject anything older than this, so a captured request cannot be replayed. */
    private const TOLERANCE_SECONDS = 300;

    /** A reply containing one of these is an opt-out, not interest. */
    private const OPT_OUT = ['unsubscribe', 'remove me', 'take me off', 'stop emailing', 'do not contact', 'opt out'];

    public function __invoke(Request $request): Response
    {
        if (!$this->verify($request)) {
            return response('invalid signature', 401);
        }

        $type = (string) $request->input('type', '');
        $data = (array) $request->input('data', []);

        return match (true) {
            str_contains($type, 'received') || str_contains($type, 'inbound') => $this->handleInbound($data),
            in_array($type, ['email.bounced', 'email.complained', 'email.failed'], true) => $this->handleFailure($type, $data),
            default => response('', 200),
        };
    }

    /** @param array<string, mixed> $data */
    private function handleInbound(array $data): Response
    {
        $from = $this->addressOf($data['from'] ?? null);
        if ($from === null) {
            return response('', 200);
        }

        // Only addresses we actually wrote to can reply to us; anything else is
        // unrelated mail arriving at the inbound domain.
        $emails = OutreachEmail::whereRaw('lower(to_email) = ?', [$from])->get();
        if ($emails->isEmpty()) {
            return response('', 200);
        }

        $subject = (string) ($data['subject'] ?? '');
        $text = (string) ($data['text'] ?? strip_tags((string) ($data['html'] ?? '')));
        $headers = (array) ($data['headers'] ?? []);

        if ($this->isAutoReply($subject, $headers)) {
            Log::info('Resend inbound: auto-reply ignored', ['from' => $from]);

            return response('', 200);
        }

        $optOut = $this->looksLikeOptOut($subject . ' ' . $text);

        foreach ($emails as $email) {
            $lead = $email->lead_id ? Lead::find($email->lead_id) : null;

            if ($optOut) {
                EmailSuppression::firstOrCreate(
                    ['user_id' => $email->user_id, 'email' => $from],
                    ['reason' => 'unsubscribe']
                );
                $lead?->update(['status' => 'lost']);
                continue;
            }

            // Never demote: a lead already marked won stays won.
            if ($lead && !in_array($lead->status, ['won', 'lost'], true)) {
                $lead->update(['status' => 'replied']);
            }
        }

        return response('', 200);
    }

    /** @param array<string, mixed> $data */
    private function handleFailure(string $type, array $data): Response
    {
        $to = $this->addressOf($data['to'] ?? null);
        if ($to === null) {
            return response('', 200);
        }

        $reason = $type === 'email.complained' ? 'complaint' : 'bounce';
        foreach (OutreachEmail::whereRaw('lower(to_email) = ?', [$to])->distinct()->pluck('user_id') as $userId) {
            EmailSuppression::firstOrCreate(['user_id' => $userId, 'email' => $to], ['reason' => $reason]);
        }
        Lead::whereRaw('lower(email) = ?', [$to])->update(['status' => 'lost']);

        return response('', 200);
    }

    /**
     * Resend signs with Svix: base64 HMAC-SHA256 over "{id}.{timestamp}.{body}"
     * keyed on the secret's decoded bytes. The header can carry several
     * space-separated versioned signatures during a secret rotation, so every
     * v1 entry is checked.
     */
    private function verify(Request $request): bool
    {
        $secret = (string) config('services.resend.webhook_secret');
        if ($secret === '') {
            Log::warning('Resend webhook received but RESEND_WEBHOOK_SECRET is unset — rejected.');

            return false;
        }

        $id = (string) $request->header('svix-id', '');
        $timestamp = (string) $request->header('svix-timestamp', '');
        $header = (string) $request->header('svix-signature', '');
        if ($id === '' || $timestamp === '' || $header === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        // Secrets are handed out as "whsec_<base64>"; the bytes are the key.
        $key = base64_decode(preg_replace('/^whsec_/', '', $secret), true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}." . $request->getContent(), $key, true));

        foreach (explode(' ', $header) as $candidate) {
            [$version, $signature] = array_pad(explode(',', $candidate, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, (string) $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Accepts "a@b.com", "Name <a@b.com>", or a list/object of either. */
    private function addressOf(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
            if (is_array($value)) {
                $value = $value['email'] ?? $value['address'] ?? null;
            }
        }

        $value = trim((string) (is_scalar($value) ? $value : ''));
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            $value = $m[1];
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : null;
    }

    /** @param array<mixed> $headers */
    private function isAutoReply(string $subject, array $headers): bool
    {
        $flat = [];
        foreach ($headers as $key => $value) {
            $name = strtolower((string) (is_array($value) ? ($value['name'] ?? $key) : $key));
            $flat[$name] = strtolower((string) (is_array($value) ? ($value['value'] ?? '') : $value));
        }

        // RFC 3834: any well-behaved autoresponder sets this.
        if (($flat['auto-submitted'] ?? 'no') !== 'no' && isset($flat['auto-submitted'])) {
            return true;
        }
        if (isset($flat['x-autoreply']) || isset($flat['x-autorespond']) || isset($flat['precedence'])
            && in_array($flat['precedence'], ['bulk', 'auto_reply', 'junk'], true)) {
            return true;
        }

        $subject = strtolower($subject);
        foreach (['out of office', 'auto-reply', 'automatic reply', 'autoreply', 'away from', 'vacation'] as $needle) {
            if (str_contains($subject, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeOptOut(string $text): bool
    {
        $text = strtolower($text);
        foreach (self::OPT_OUT as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
