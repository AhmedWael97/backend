<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the TikTok Events API (server-side conversion send).
 *
 * Complements the client-side pixel (tracker/[locale]/layout.tsx): the pixel
 * alone misses ad-blockers, iOS ITP, and in-app-browser tabs closed before the
 * conversion beacon goes out. Pass the same `eventId` used by the client's
 * `ttq.track(event, props, {event_id})` call so TikTok dedups the two instead
 * of double-counting the conversion.
 *
 * Degrades gracefully: no-ops entirely if TIKTOK_EVENTS_ACCESS_TOKEN isn't set.
 */
class TikTokEventsService
{
    private const API_URL = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private ?string $pixelCode;
    private ?string $accessToken;

    public function __construct()
    {
        $this->pixelCode = config('services.tiktok.pixel_code') ?: null;
        $this->accessToken = config('services.tiktok.access_token') ?: null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->pixelCode) && !empty($this->accessToken);
    }

    /**
     * Send one event. $user should contain any of: email, ip, user_agent.
     * Email is SHA-256 hashed here — never send it in plaintext to TikTok.
     */
    public function track(string $event, string $eventId, array $user = [], array $properties = []): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $userData = [];
        if (!empty($user['email'])) {
            $userData['email'] = hash('sha256', strtolower(trim($user['email'])));
        }
        if (!empty($user['ip'])) {
            $userData['ip'] = $user['ip'];
        }
        if (!empty($user['user_agent'])) {
            $userData['user_agent'] = $user['user_agent'];
        }

        try {
            Http::withHeaders([
                'Access-Token' => $this->accessToken,
                'Content-Type' => 'application/json',
            ])->timeout(5)->post(self::API_URL, [
                'event_source' => 'web',
                'event_source_id' => $this->pixelCode,
                'data' => [[
                    'event' => $event,
                    'event_id' => $eventId,
                    'event_time' => time(),
                    'user' => $userData,
                    'properties' => $properties,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('TikTok Events API send failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
