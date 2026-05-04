<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Automatically send the configured API key headers so ApiKeyAuth
     * middleware passes in all feature / unit tests without each test
     * having to call withHeaders() manually.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $public = config('app.api_public_key');
        $secret = config('app.api_secret_key');

        if ($public && $secret) {
            $this->withHeaders([
                'X-Public-Key' => $public,
                'X-Secret-Key' => $secret,
            ]);
        }
    }

    /**
     * Automatically prepend /v1 to legacy /api/... test paths so tests
     * don't need to hard-code the version prefix.
     */
    protected function prepareUrlForRequest($uri): string
    {
        if (str_starts_with($uri, '/api/') && !str_starts_with($uri, '/api/v')) {
            $uri = '/api/v1/' . substr($uri, 5);
        }

        return parent::prepareUrlForRequest($uri);
    }
}
