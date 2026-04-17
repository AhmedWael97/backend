<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTrackingEvent;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class TrackController extends Controller
{
    /**
     * Receive a tracking event from the browser snippet.
     *
     * POST /api/track
     *
     * Deliberately lean: validate token, queue event, return 204 instantly.
     * No auth:sanctum — this endpoint is public (called from visitor browsers).
     */
    public function __invoke(Request $request): Response
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Eye-Token',
        ];

        $body = $request->json()->all();

        // Support both a single event object and a batch array
        $events = isset($body[0]) ? $body : [$body];

        // All events in a batch share the same token — validate once
        $token = $events[0]['t'] ?? $request->header('X-Eye-Token');

        if (!$token) {
            return response('', 400, $corsHeaders);
        }

        $domain = Domain::where(function ($q) use ($token) {
            $q->where('script_token', $token)
                ->orWhere('previous_script_token', $token);
        })->where('active', true)->first();

        if (!$domain) {
            return response('', 401, $corsHeaders);
        }

        if ($domain->previous_script_token === $token && !$domain->isTokenInGracePeriod()) {
            return response('', 401, $corsHeaders);
        }

        // Mark script as verified (first hit)
        if (!$domain->isScriptVerified()) {
            cache()->put("script_verified:{$domain->script_token}", true, 600);
        }

        $ip = $request->ip();
        $ua = substr((string) $request->userAgent(), 0, 500);
        $ts = now()->toIso8601String();

        foreach ($events as $event) {
            $payload = [
                'domain_id' => $domain->id,
                'session_id' => $this->sanitizeSessionId($event['sid'] ?? null),
                'visitor_id' => $this->sanitizeVisitorId($event['vid'] ?? null),
                'type' => $this->sanitizeEventType($event['e'] ?? 'pageview'),
                'url' => $this->sanitizeUrl($event['u'] ?? null),
                'referrer' => $this->sanitizeUrl($event['r'] ?? null),
                'title' => $this->sanitizeString($event['pt'] ?? null, 255),
                'props' => $this->sanitizeProps($event['p'] ?? null),
                'screen_w' => (int) ($event['sw'] ?? 0),
                'screen_h' => (int) ($event['sh'] ?? 0),
                'duration' => (int) ($event['d'] ?? 0),
                'ip' => $ip,
                'user_agent' => $ua,
                'ts' => $ts,
            ];

            ProcessTrackingEvent::dispatch($payload)->onQueue('tracking');
        }

        return response('', 204, $corsHeaders);
    }

    private function sanitizeSessionId(mixed $value): string
    {
        $str = (string) ($value ?? '');
        return preg_match('/^[a-f0-9\-]{8,64}$/i', $str) ? $str : Str::uuid()->toString();
    }

    private function sanitizeVisitorId(mixed $value): string
    {
        $str = (string) ($value ?? '');
        return preg_match('/^[a-f0-9\-]{8,64}$/i', $str) ? $str : Str::uuid()->toString();
    }

    private function sanitizeEventType(mixed $value): string
    {
        $allowed = ['pageview', 'custom', 'pageleave', 'click', 'scroll', 'form_submit', 'identify'];
        $str = strtolower(trim((string) ($value ?? 'pageview')));
        return in_array($str, $allowed, true) ? $str : 'custom';
    }

    private function sanitizeUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $url = substr(trim((string) $value), 0, 2048);
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    private function sanitizeString(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        return substr(strip_tags((string) $value), 0, $maxLen);
    }

    private function sanitizeProps(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        // Only scalar values, max 20 keys, max 100 chars per key/value
        $sanitized = [];
        foreach (array_slice($value, 0, 20) as $k => $v) {
            $key = substr(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k), 0, 100);
            $sanitized[$key] = is_scalar($v) ? substr((string) $v, 0, 100) : null;
        }
        return $sanitized;
    }
}
