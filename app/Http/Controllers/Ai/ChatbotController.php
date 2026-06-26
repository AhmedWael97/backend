<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use App\Models\Domain;
use App\Services\ClickHouseService;
use App\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI Assistant Chatbot
 *
 * Endpoints:
 *   GET  /chatbot/{domainId}/sessions              – list sessions for sidebar
 *   POST /chatbot/{domainId}/sessions              – start a new session
 *   GET  /chatbot/{domainId}/sessions/{sessionId}  – load a session + messages
 *   POST /chatbot/{domainId}/sessions/{sessionId}/message – send a message
 *   DELETE /chatbot/{domainId}/sessions/{sessionId}       – delete a session
 *
 * The chatbot is aware of the domain's real analytics data.
 * On each new session a context_snapshot is built from ClickHouse and stored
 * so subsequent turns don't need to re-query the database.
 */
class ChatbotController extends Controller
{
    /** Max conversation turns kept in memory per session (older ones pruned). */
    private const MAX_HISTORY = 20;

    public function __construct(
        private readonly ClickHouseService $ch,
        private readonly OpenAiService $openai,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Session list
    // ─────────────────────────────────────────────────────────────────────────

    public function sessions(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);

        $sessions = ChatbotSession::where('domain_id', $domain->id)
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'title' => $s->context_snapshot['title'] ?? 'New chat',
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
                'messages_count' => $s->messages_count,
            ]);

        return $this->success($sessions);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Start a new session
    // ─────────────────────────────────────────────────────────────────────────

    public function startSession(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);

        $snapshot = $this->buildContextSnapshot($domain->id);

        $session = ChatbotSession::create([
            'user_id' => $request->user()->id,
            'domain_id' => $domain->id,
            'context_snapshot' => $snapshot,
        ]);

        return $this->success([
            'id' => $session->id,
            'title' => $snapshot['title'],
            'context_snapshot' => $snapshot,
            'messages' => [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Load session + messages
    // ─────────────────────────────────────────────────────────────────────────

    public function showSession(Request $request, int $domainId, int $sessionId): JsonResponse
    {
        $session = $this->authorizedSession($request, $domainId, $sessionId);

        return $this->success([
            'id' => $session->id,
            'title' => $session->context_snapshot['title'] ?? 'Chat',
            'context_snapshot' => $session->context_snapshot,
            'messages' => $session->messages->map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'tokens_used' => $m->tokens_used,
                'created_at' => $m->created_at,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Send a message
    // ─────────────────────────────────────────────────────────────────────────

    public function sendMessage(Request $request, int $domainId, int $sessionId): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $session = $this->authorizedSession($request, $domainId, $sessionId);
        $userText = trim($request->input('message'));

        // Persist user message
        ChatbotMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $userText,
            'tokens_used' => 0,
        ]);

        // Build history for OpenAI (last N turns only)
        $history = $session->messages()
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        $systemPrompt = $this->buildSystemPrompt($session->context_snapshot ?? []);

        try {
            $result = $this->openai->chat($systemPrompt, $history);
        } catch (\RuntimeException $e) {
            return response()->json([
                'statusCode' => 503,
                'statusText' => 'failed',
                'data' => ['message' => 'AI service unavailable. ' . $e->getMessage()],
            ], 503);
        }

        // Persist assistant reply
        $assistantMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $result['text'],
            'tokens_used' => $result['tokens_used'],
        ]);

        // Update session title from first exchange if it's still the default
        $snapshot = $session->context_snapshot ?? [];
        if (($snapshot['title'] ?? 'New chat') === 'New chat') {
            $snapshot['title'] = $this->generateTitle($userText);
            $session->update(['context_snapshot' => $snapshot]);
        } else {
            $session->touch();
        }

        return $this->success([
            'id' => $assistantMsg->id,
            'role' => 'assistant',
            'content' => $result['text'],
            'tokens_used' => $result['tokens_used'],
            'created_at' => $assistantMsg->created_at,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Delete session
    // ─────────────────────────────────────────────────────────────────────────

    public function deleteSession(Request $request, int $domainId, int $sessionId): JsonResponse
    {
        $session = $this->authorizedSession($request, $domainId, $sessionId);
        $session->delete();

        return $this->success(['deleted' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Context snapshot builder
    // ─────────────────────────────────────────────────────────────────────────

    private function buildContextSnapshot(int $domainId): array
    {
        $now = now();
        $start = $now->copy()->subDays(30)->format('Y-m-d H:i:s');
        $end = $now->format('Y-m-d H:i:s');

        // Core summary
        $summary = $this->ch->select("
            SELECT
                countIf(type = 'pageview')   AS pageviews,
                uniq(visitor_id)              AS unique_visitors,
                uniq(session_id)              AS sessions,
                round(avgIf(duration, duration > 0)) AS avg_duration
            FROM events
            WHERE domain_id = {$domainId}
              AND ts >= '{$start}' AND ts < '{$end}'
        ")[0] ?? [];

        // Bounce rate
        $bounceData = $this->ch->select("
            SELECT countIf(pv=1) AS bounced, count() AS total
            FROM (
                SELECT session_id, countIf(type='pageview') AS pv
                FROM events WHERE domain_id={$domainId}
                  AND ts>='{$start}' AND ts<'{$end}'
                GROUP BY session_id
            )
        ")[0] ?? ['bounced' => 0, 'total' => 1];

        $bounceRate = $bounceData['total'] > 0
            ? round($bounceData['bounced'] / $bounceData['total'] * 100, 1)
            : 0;

        // Top 5 pages
        $topPages = $this->ch->select("
            SELECT url, count() AS pv
            FROM events
            WHERE domain_id={$domainId} AND type='pageview'
              AND ts>='{$start}' AND ts<'{$end}'
            GROUP BY url ORDER BY pv DESC LIMIT 5
        ");

        // Top 5 countries
        $topCountries = $this->ch->select("
            SELECT country, uniq(visitor_id) AS visitors
            FROM events
            WHERE domain_id={$domainId} AND country != ''
              AND ts>='{$start}' AND ts<'{$end}'
            GROUP BY country ORDER BY visitors DESC LIMIT 5
        ");

        // Top 5 referrers
        $topReferrers = $this->ch->select("
            SELECT referrer, count() AS hits
            FROM events
            WHERE domain_id={$domainId} AND referrer != ''
              AND type='pageview'
              AND ts>='{$start}' AND ts<'{$end}'
            GROUP BY referrer ORDER BY hits DESC LIMIT 5
        ");

        // Device split
        $devices = $this->ch->select("
            SELECT device_type, uniq(visitor_id) AS visitors
            FROM events
            WHERE domain_id={$domainId} AND device_type != ''
              AND ts>='{$start}' AND ts<'{$end}'
            GROUP BY device_type ORDER BY visitors DESC
        ");

        // Weekly trend — last 4 weeks vs previous 4 weeks
        $prevStart = $now->copy()->subDays(60)->format('Y-m-d H:i:s');
        $prevEnd = $now->copy()->subDays(30)->format('Y-m-d H:i:s');
        $prevSummary = $this->ch->select("
            SELECT uniq(visitor_id) AS unique_visitors, countIf(type='pageview') AS pageviews
            FROM events
            WHERE domain_id={$domainId}
              AND ts>='{$prevStart}' AND ts<'{$prevEnd}'
        ")[0] ?? [];

        $currVisitors = (int) ($summary['unique_visitors'] ?? 0);
        $prevVisitors = (int) ($prevSummary['unique_visitors'] ?? 0);
        $visitorChange = $prevVisitors > 0
            ? round(($currVisitors - $prevVisitors) / $prevVisitors * 100, 1)
            : null;

        return [
            'title' => 'New chat',
            'period' => 'Last 30 days',
            'domain_id' => $domainId,
            'summary' => [
                'pageviews' => (int) ($summary['pageviews'] ?? 0),
                'unique_visitors' => $currVisitors,
                'sessions' => (int) ($summary['sessions'] ?? 0),
                'avg_duration_s' => (int) ($summary['avg_duration'] ?? 0),
                'bounce_rate' => $bounceRate,
            ],
            'vs_prev_period' => [
                'visitor_change_pct' => $visitorChange,
            ],
            'top_pages' => array_map(fn($r) => ['url' => $r['url'], 'pageviews' => (int) $r['pv']], $topPages),
            'top_countries' => array_map(fn($r) => ['country' => $r['country'], 'visitors' => (int) $r['visitors']], $topCountries),
            'top_referrers' => array_map(fn($r) => ['referrer' => $r['referrer'], 'hits' => (int) $r['hits']], $topReferrers),
            'devices' => array_map(fn($r) => ['device' => $r['device_type'], 'visitors' => (int) $r['visitors']], $devices),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // System prompt
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSystemPrompt(array $ctx): string
    {
        $period = $ctx['period'] ?? 'last 30 days';
        $summary = $ctx['summary'] ?? [];
        $topPages = $ctx['top_pages'] ?? [];
        $topCountries = $ctx['top_countries'] ?? [];
        $topReferrers = $ctx['top_referrers'] ?? [];
        $devices = $ctx['devices'] ?? [];
        $vs = $ctx['vs_prev_period'] ?? [];

        $pagesText = implode(', ', array_map(fn($p) => "{$p['url']} ({$p['pageviews']} views)", $topPages));
        $countriesText = implode(', ', array_map(fn($c) => "{$c['country']} ({$c['visitors']} visitors)", $topCountries));
        $referrersText = implode(', ', array_map(fn($r) => "{$r['referrer']} ({$r['hits']} hits)", $topReferrers));
        $devicesText = implode(', ', array_map(fn($d) => "{$d['device']} ({$d['visitors']} visitors)", $devices));

        $changeText = isset($vs['visitor_change_pct'])
            ? "Visitor count changed by {$vs['visitor_change_pct']}% compared to the previous 30-day period."
            : '';

        return <<<PROMPT
You are an expert web analytics assistant for the EYE analytics platform. 
You help users understand their website traffic and suggest improvements.
Be concise, specific, and always ground your answers in the data provided.
When you don't have data for something, say so clearly.
Format responses in clear paragraphs. Use bullet points for lists. Keep answers under 300 words unless the user asks for more detail.

--- ANALYTICS CONTEXT ({$period}) ---
Pageviews: {$summary['pageviews']}
Unique Visitors: {$summary['unique_visitors']}
Sessions: {$summary['sessions']}
Avg Session Duration: {$summary['avg_duration_s']}s
Bounce Rate: {$summary['bounce_rate']}%
{$changeText}

Top Pages: {$pagesText}
Top Countries: {$countriesText}
Top Referrers: {$referrersText}
Devices: {$devicesText}
---
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function generateTitle(string $firstMessage): string
    {
        $title = substr($firstMessage, 0, 50);
        return strlen($firstMessage) > 50 ? $title . '…' : $title;
    }

    private function authorizedDomain(Request $request, int $domainId): \App\Models\Domain
    {
        return Domain::where('id', $domainId)
            ->accessibleBy($request->user())
            ->firstOrFail();
    }

    private function authorizedSession(Request $request, int $domainId, int $sessionId): ChatbotSession
    {
        $this->authorizedDomain($request, $domainId);

        return ChatbotSession::where('id', $sessionId)
            ->where('domain_id', $domainId)
            ->where('user_id', $request->user()->id)
            ->with('messages')
            ->firstOrFail();
    }

}
