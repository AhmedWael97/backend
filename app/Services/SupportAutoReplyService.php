<?php

namespace App\Services;

use App\Models\SupportChat;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Fully-automated AI replies for the live support chat — every customer
 * message gets an instant answer, no human review before it sends.
 *
 * Kept deliberately narrow: the system prompt tells the model what EYE is,
 * what it doesn't know about (a specific account's billing/payment status
 * unless we hand it that data), and to say so plainly instead of guessing.
 * A human superadmin can still see and reply to any thread from the admin
 * inbox — the AI reply doesn't lock the conversation.
 */
class SupportAutoReplyService
{
    private const MAX_HISTORY = 20;

    public function __construct(private readonly OpenAiService $ai)
    {
    }

    /**
     * Generate a reply for the latest customer message and store it.
     * Best-effort: swallows AI/API failures so a broken key or a rate limit
     * never blocks the customer's own message from saving.
     */
    public function reply(SupportChat $chat, ?User $user): void
    {
        try {
            $history = $chat->messages()
                ->orderByDesc('id')
                ->limit(self::MAX_HISTORY)
                ->get()
                ->reverse()
                ->map(fn ($m) => ['role' => $m->is_admin ? 'assistant' : 'user', 'content' => $m->body])
                ->values()
                ->toArray();

            $result = $this->ai->chat($this->systemPrompt($user), $history, 600);
            $text = trim($result['text']);
            if ($text === '') {
                return;
            }

            $chat->messages()->create([
                'sender_user_id' => null,
                'is_admin' => true,
                'is_ai' => true,
                'body' => $text,
            ]);

            $chat->update([
                'unread_for_user' => $chat->unread_for_user + 1,
                'last_message_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Support auto-reply failed', ['chat_id' => $chat->id, 'msg' => $e->getMessage()]);
        }
    }

    private function systemPrompt(?User $user): string
    {
        $account = 'The visitor is not logged in — you have no account data for them.';

        if ($user) {
            $sub = $user->effectiveSubscription();
            $plan = $sub?->plan?->name ?? 'no active plan (trial may have expired)';
            $trialEnd = $sub?->current_period_end?->toDateString() ?? 'n/a';
            $domainCount = $user->domains()->count();
            $account = <<<TXT
            Logged-in user: {$user->name} <{$user->email}>
            Plan: {$plan}
            Current period / trial end: {$trialEnd}
            Domains tracked: {$domainCount}
            TXT;
        }

        return <<<PROMPT
        You are the support assistant for EYE — a privacy-first website analytics SaaS
        (like Mixpanel/Hotjar): heatmaps, session replay, SEO checker, AI traffic reports,
        campaign/ROAS tracking, alerts, cohort retention, A/B testing, and an agency/team
        tier. New accounts get a 30-day free trial, no card required. Paid plans are billed
        via Paymob (card, charged in EGP) or manual bank transfer — both configured in
        Settings → Billing. Users add a site under Settings → Domains, then paste a tracking
        snippet into their site's <head>.

        Answer the visitor's question directly and concisely (a few sentences, not an essay).
        Friendly, plain language, no corporate filler.

        {$account}

        Rules:
        - Never invent specifics you don't have — a payment status, a refund amount, an
          exact bug cause. If you don't know, say a team member will follow up, don't guess.
        - Don't promise refunds, discounts, or custom deals — that needs a human.
        - If the message is abusive, spam, or clearly not about EYE, reply briefly and
          neutrally; don't engage.
        - Reply in the same language the visitor wrote in (Arabic in, Arabic out).
        PROMPT;
    }
}
