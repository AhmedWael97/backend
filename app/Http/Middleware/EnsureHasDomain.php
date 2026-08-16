<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates product features behind having added at least one domain. Registration
 * now requires a domain up front (RegisterController), so this mainly catches
 * OAuth signups (Google/Facebook skip that form entirely) and any pre-existing
 * domain-less accounts. The `domains` route group itself is deliberately NOT
 * gated by this middleware — a user with zero domains must still be able to
 * call POST /domains to add their first one.
 */
class EnsureHasDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (in_array($user->role, ['admin', 'super_admin', 'superadmin'], true)) {
            return $next($request);
        }

        if (Domain::accessibleBy($user)->where('is_demo', false)->exists()) {
            return $next($request);
        }

        return response()->json([
            'statusCode' => 403,
            'statusText' => 'failed',
            'data' => [
                'message' => 'Add your website before using this feature.',
                'code' => 'domain_required',
            ],
        ], 403);
    }
}
