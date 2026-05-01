<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate requests using a single app-level public/secret key pair.
 *
 * The key pair is shared between the backend and the frontend application.
 * Both sides store the values in their respective .env files:
 *
 *   Backend .env:
 *     APP_PUBLIC_KEY=pk_live_xxxxxxxxxxxxxxxx
 *     APP_SECRET_KEY=sk_live_xxxxxxxxxxxxxxxx
 *
 *   Frontend .env (Next.js / Vue / React / etc.):
 *     API_PUBLIC_KEY=pk_live_xxxxxxxxxxxxxxxx
 *     API_SECRET_KEY=sk_live_xxxxxxxxxxxxxxxx
 *
 * Every request from the frontend must include both headers:
 *   X-Public-Key: pk_live_xxxxxxxxxxxxxxxx
 *   X-Secret-Key: sk_live_xxxxxxxxxxxxxxxx
 */
class ApiKeyAuth
{
    private function apiError(string $message, int $status = 401): Response
    {
        return response()->json([
            'statusCode' => $status,
            'statusText' => 'failed',
            'data' => [
                'message' => $message,
            ],
        ], $status);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Always pass OPTIONS preflight requests — browsers don't send auth headers on preflights.
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // Tracker endpoints authenticate via script_token, not API keys — skip key check.
        $path = ltrim($request->path(), '/');
        if (preg_match('#^api/(v1/)?(track|collect)(/|$)#', $path)) {
            return $next($request);
        }

        $expectedPublic = config('app.api_public_key');
        $expectedSecret = config('app.api_secret_key');

        // Middleware is a no-op if keys are not configured (graceful in dev).
        if (!$expectedPublic || !$expectedSecret) {
            return $next($request);
        }

        $incomingPublic = $request->header('X-Public-Key');
        $incomingSecret = $request->header('X-Secret-Key');

        if (!$incomingPublic || !$incomingSecret) {
            return $this->apiError('Missing API key headers. Send X-Public-Key and X-Secret-Key.');
        }

        // Use hash_equals to prevent timing-based side-channel attacks.
        $validPublic = hash_equals($expectedPublic, $incomingPublic);
        $validSecret = hash_equals($expectedSecret, $incomingSecret);

        if (!$validPublic || !$validSecret) {
            return $this->apiError('Invalid API keys.');
        }

        return $next($request);
    }
}
