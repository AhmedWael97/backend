<?php

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\OutreachEmail;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Compliant AI-assisted outreach.
 *  - draft(): AI writes a personalised email for a lead — for human review.
 *  - send():  sends ONE reviewed email, with suppression + daily-cap + an
 *             unsubscribe link. Never auto-sends.
 *  - unsubscribe(): public link target → adds to suppression list.
 *  - mailgunWebhook(): bounces/complaints/unsubscribes → suppression.
 */
class OutreachController extends Controller
{
    private const DAILY_CAP = 100; // per-user safety cap for deliverability/compliance

    public function draft(Request $request, AnthropicService $ai): JsonResponse
    {
        $lead = Lead::where('user_id', $request->user()->id)
            ->findOrFail((int) $request->input('lead_id'));

        $sender = $request->user()->name ?? 'the EYE Analytics team';
        $system = 'You write short, friendly, non-spammy B2B outreach emails (under 120 words). '
            . 'Personalised, specific, one clear ask (a quick call/reply). No hype, no false claims. '
            . 'Return ONLY JSON: {"subject": "...", "body": "..."} with plain-text body (use \n for line breaks).';
        $userMsg = "Write an outreach email from {$sender} (EYE Analytics — privacy-first website analytics, heatmaps, "
            . "session replay, campaign ROAS) to this prospect:\n"
            . "Company: " . ($lead->company ?: 'Unknown') . "\n"
            . "Website: " . ($lead->website ?: 'Unknown') . "\n"
            . "Context: " . ($lead->notes ?: 'They visited our site.') . "\n"
            . "Goal: introduce EYE and ask for a short reply if interested.";

        $subject = '';
        $body = '';
        try {
            $res = $ai->complete($system, $userMsg, 600);
            $subject = (string) ($res['subject'] ?? '');
            $body = (string) ($res['body'] ?? '');
        } catch (\Throwable $e) {
            report($e);
        }

        // Fallback template if AI is unconfigured or failed.
        if ($subject === '' || $body === '') {
            $co = $lead->company ?: 'your team';
            $subject = "A quick idea for {$co}";
            $body = "Hi,\n\nI'm with EYE Analytics — we help teams see exactly how visitors use their site "
                . "(heatmaps, session replay) and which campaigns actually drive revenue.\n\n"
                . "Would a 15-minute look be useful for {$co}? Happy to share a quick walkthrough.\n\nBest,\n{$sender}";
        }

        return $this->success(['subject' => $subject, 'body' => $body]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
        $user = $request->user();
        $lead = Lead::where('user_id', $user->id)->findOrFail($data['lead_id']);

        $to = (string) $lead->email;
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->error('This lead has no valid email address.', 422);
        }

        // Compliance gate 1: suppression list.
        if (EmailSuppression::where('user_id', $user->id)->where('email', $to)->exists()) {
            OutreachEmail::create(['user_id' => $user->id, 'lead_id' => $lead->id, 'to_email' => $to, 'subject' => $data['subject'], 'body' => $data['body'], 'status' => 'skipped', 'unsubscribe_token' => Str::random(40)]);
            return $this->error('This address has unsubscribed/bounced and was skipped.', 409);
        }

        // Compliance gate 2: daily send cap (deliverability + anti-spam).
        $capKey = "outreach:cap:{$user->id}:" . now()->format('Y-m-d');
        $sentToday = (int) Redis::incr($capKey);
        Redis::expire($capKey, 90000);
        if ($sentToday > self::DAILY_CAP) {
            Redis::decr($capKey);
            return $this->error('Daily send limit reached (' . self::DAILY_CAP . '). Try again tomorrow.', 429);
        }

        $token = Str::random(40);
        $unsubUrl = url("/api/v1/outreach/unsubscribe/{$token}");
        $bodyHtml = nl2br(e($data['body']))
            . '<br><br><hr style="border:none;border-top:1px solid #eee">'
            . '<p style="font-size:12px;color:#888">' . e(config('app.name', 'EYE Analytics'))
            . ' — you received this as a business contact. '
            . '<a href="' . $unsubUrl . '">Unsubscribe</a>.</p>';

        $status = 'sent';
        try {
            Mail::html($bodyHtml, function ($m) use ($to, $data) {
                $m->to($to)->subject($data['subject']);
            });
        } catch (\Throwable $e) {
            report($e);
            $status = 'failed';
        }

        OutreachEmail::create([
            'user_id' => $user->id, 'lead_id' => $lead->id, 'to_email' => $to,
            'subject' => $data['subject'], 'body' => $data['body'],
            'status' => $status, 'unsubscribe_token' => $token, 'sent_at' => $status === 'sent' ? now() : null,
        ]);

        if ($status === 'sent') {
            $lead->update(['status' => $lead->status === 'new' ? 'contacted' : $lead->status, 'last_contacted_at' => now()]);
        }

        return $status === 'sent'
            ? $this->success(['message' => 'Sent.', 'sent_today' => $sentToday])
            : $this->error('Email could not be sent (check mail configuration).', 502);
    }

    /** Public: clicked from an email's unsubscribe link. */
    public function unsubscribe(string $token): Response
    {
        $email = OutreachEmail::where('unsubscribe_token', $token)->first();
        if ($email) {
            EmailSuppression::firstOrCreate(
                ['user_id' => $email->user_id, 'email' => $email->to_email],
                ['reason' => 'unsubscribe']
            );
            Lead::where('user_id', $email->user_id)->where('email', $email->to_email)->update(['status' => 'lost']);
        }
        return response('<html><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>You have been unsubscribed.</h2><p>You will not receive further emails.</p></body></html>', 200)
            ->header('Content-Type', 'text/html');
    }

    /** Public: Mailgun events → auto-suppress bounces/complaints/unsubscribes. */
    public function mailgunWebhook(Request $request): Response
    {
        $event = $request->input('event-data.event') ?? $request->input('event');
        $recipient = $request->input('event-data.recipient') ?? $request->input('recipient');
        if ($recipient && in_array($event, ['failed', 'bounced', 'complained', 'unsubscribed'], true)) {
            // Suppress for every user who emailed this address.
            $userIds = OutreachEmail::where('to_email', $recipient)->distinct()->pluck('user_id');
            foreach ($userIds as $uid) {
                EmailSuppression::firstOrCreate(['user_id' => $uid, 'email' => $recipient], ['reason' => $event === 'complained' ? 'complaint' : 'bounce']);
            }
        }
        return response('', 200);
    }
}
