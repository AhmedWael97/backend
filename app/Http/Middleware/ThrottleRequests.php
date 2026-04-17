<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRequests extends BaseThrottleRequests
{
    /**
     * Handle an incoming request.
     *
     * If the rate-limit store (Redis) is unavailable, fail open — allow the
     * request to proceed rather than returning a 500 to the user.
     */
    public function handle($request, \Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        try {
            return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
        } catch (\RedisException $e) {
            // Redis is down — fail open so the request still works
            report($e);
            return $next($request);
        } catch (\Illuminate\Redis\Connections\PackedCommand $e) {
            report($e);
            return $next($request);
        } catch (\Exception $e) {
            // Catch any other connection-level failure from the limiter store
            if (str_contains($e->getMessage(), 'redis') || str_contains($e->getMessage(), 'getaddrinfo')) {
                report($e);
                return $next($request);
            }
            throw $e;
        }
    }
}
