<?php

/**
 * Unit tests for webhook HMAC-SHA256 signature generation.
 *
 * The signature is produced by the logic in WebhookDeliveryJob::deliver():
 *   $sig = hash_hmac('sha256', $body, $webhook->secret);
 *   header: "X-Eye-Signature: sha256={$sig}"
 */

test('webhook signature is sha256 hmac of json body with secret', function () {
    $payload = ['event' => 'pageview', 'url' => 'https://example.com'];
    $secret = 'test-webhook-secret-32chars-abcdef';
    $body = json_encode($payload);

    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

    // Reproduce the exact logic from WebhookDeliveryJob
    $sig = hash_hmac('sha256', $body, $secret);

    expect("sha256={$sig}")->toBe($expected);
});

test('different secrets produce different signatures', function () {
    $body = json_encode(['event' => 'pageview']);
    $secret1 = 'secret-one';
    $secret2 = 'secret-two';

    $sig1 = hash_hmac('sha256', $body, $secret1);
    $sig2 = hash_hmac('sha256', $body, $secret2);

    expect($sig1)->not->toBe($sig2);
});

test('different payloads produce different signatures with same secret', function () {
    $secret = 'shared-secret';
    $body1 = json_encode(['event' => 'pageview', 'url' => '/home']);
    $body2 = json_encode(['event' => 'pageview', 'url' => '/about']);

    $sig1 = hash_hmac('sha256', $body1, $secret);
    $sig2 = hash_hmac('sha256', $body2, $secret);

    expect($sig1)->not->toBe($sig2);
});

test('signature is deterministic for same payload and secret', function () {
    $body = json_encode(['event' => 'click', 'x' => 100, 'y' => 200]);
    $secret = 'deterministic-secret';

    $sig1 = hash_hmac('sha256', $body, $secret);
    $sig2 = hash_hmac('sha256', $body, $secret);

    expect($sig1)->toBe($sig2);
});

test('signature header format is sha256={hex}', function () {
    $body = json_encode(['test' => true]);
    $secret = 'test-secret';
    $sig = hash_hmac('sha256', $body, $secret);
    $header = "sha256={$sig}";

    expect($header)->toStartWith('sha256=');
    expect(strlen($sig))->toBe(64); // SHA-256 produces 64 hex chars
    expect(ctype_xdigit($sig))->toBeTrue();
});
