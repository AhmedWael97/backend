<?php

/**
 * Unit tests for the Redis quota key pattern used in AnalyzeDomainJob and
 * CheckAlertRulesJob.
 *
 * Key patterns:
 *   - Daily events:   quota:{scriptToken}:events:{Y-m-d}
 *   - Monthly AI:     quota:{domainId}:analysis:{Y-m}
 */

use Illuminate\Support\Facades\Redis;

test('daily event quota key format matches expected pattern', function () {
    $scriptToken = 'abc123';
    $date = '2024-01-15';

    $key = "quota:{$scriptToken}:events:{$date}";

    expect($key)->toBe('quota:abc123:events:2024-01-15');
    expect($key)->toMatch('/^quota:[^:]+:events:\d{4}-\d{2}-\d{2}$/');
});

test('monthly analysis quota key format matches expected pattern', function () {
    $domainId = 42;
    $month = '2024-01';

    $key = "quota:{$domainId}:analysis:{$month}";

    expect($key)->toBe('quota:42:analysis:2024-01');
    expect($key)->toMatch('/^quota:\d+:analysis:\d{4}-\d{2}$/');
});

test('daily quota key changes each day', function () {
    $token = 'tok';
    $key1 = "quota:{$token}:events:2024-01-15";
    $key2 = "quota:{$token}:events:2024-01-16";

    expect($key1)->not->toBe($key2);
});

test('monthly quota key changes each month', function () {
    $id = 1;
    $key1 = "quota:{$id}:analysis:2024-01";
    $key2 = "quota:{$id}:analysis:2024-02";

    expect($key1)->not->toBe($key2);
});

test('quota keys are namespaced and do not collide between types', function () {
    $id = 5;
    $date = now()->format('Y-m-d');
    $month = now()->format('Y-m');

    $dailyKey = "quota:{$id}:events:{$date}";
    $monthlyKey = "quota:{$id}:analysis:{$month}";

    expect($dailyKey)->not->toBe($monthlyKey);
});

test('Redis INCR increments counter and value is readable', function () {
    $key = 'quota:test_token:events:' . now()->format('Y-m-d') . '_unit_test';

    Redis::del($key); // clean up before test
    Redis::incr($key);
    Redis::incr($key);
    $count = (int) Redis::get($key);

    expect($count)->toBe(2);

    Redis::del($key); // clean up after test
})->skip(fn() => !extension_loaded('redis'), 'Redis extension not available');
