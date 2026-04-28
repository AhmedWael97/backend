<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
