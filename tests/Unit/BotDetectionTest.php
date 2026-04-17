<?php

/**
 * Unit tests for the bot detection logic in ProcessTrackingEvent::parseDevice().
 *
 * The logic (copied from the job) classifies a UA as 'bot' if any of these
 * patterns appear (case-insensitive): bot, crawler, spider, slurp, curl, wget.
 * Otherwise it classifies by screen width / device hints.
 */

/**
 * Replicate the exact parseDevice logic from ProcessTrackingEvent for isolated testing.
 */
function parseDevice(string $ua, int $screenW = 1280): string
{
    foreach (['bot', 'crawler', 'spider', 'slurp', 'curl', 'wget'] as $bot) {
        if (str_contains(strtolower($ua), $bot)) {
            return 'bot';
        }
    }
    if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPod'))
        return 'mobile';
    if (str_contains($ua, 'Android') && str_contains($ua, 'Mobile'))
        return 'mobile';
    if (str_contains($ua, 'iPad') || str_contains($ua, 'Android'))
        return 'tablet';
    if ($screenW > 0 && $screenW < 768)
        return 'mobile';
    return 'desktop';
}

// --- Bot detection ---

test('Googlebot is detected as bot', function () {
    expect(parseDevice('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'))->toBe('bot');
});

test('Bingbot is detected as bot', function () {
    expect(parseDevice('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'))->toBe('bot');
});

test('crawler UA is detected as bot', function () {
    expect(parseDevice('Mozilla/5.0 (compatible; AhrefsBot/7.0; crawler)'))->toBe('bot');
});

test('spider UA is detected as bot', function () {
    expect(parseDevice('spider/1.0'))->toBe('bot');
});

test('Yahoo Slurp is detected as bot', function () {
    expect(parseDevice('Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)'))->toBe('bot');
});

test('curl is detected as bot', function () {
    expect(parseDevice('curl/7.68.0'))->toBe('bot');
});

test('wget is detected as bot', function () {
    expect(parseDevice('Wget/1.20.3 (linux-gnu)'))->toBe('bot');
});

// --- Real devices ---

test('iPhone UA is classified as mobile', function () {
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15';
    expect(parseDevice($ua))->toBe('mobile');
});

test('Android Mobile UA is classified as mobile', function () {
    $ua = 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.91 Mobile Safari/537.36';
    expect(parseDevice($ua))->toBe('mobile');
});

test('iPad UA is classified as tablet', function () {
    $ua = 'Mozilla/5.0 (iPad; CPU OS 15_0 like Mac OS X) AppleWebKit/605.1.15';
    expect(parseDevice($ua))->toBe('tablet');
});

test('narrow screen width is classified as mobile', function () {
    expect(parseDevice('Mozilla/5.0 (Windows NT 10.0)', 375))->toBe('mobile');
});

test('desktop Chrome UA is classified as desktop', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    expect(parseDevice($ua, 1920))->toBe('desktop');
});
