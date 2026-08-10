<?php

namespace App\Http\Controllers\Domain;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;

/**
 * No-login install guide, keyed by the domain's own script_token (the same
 * value already shipped client-side in the public tracking snippet — this
 * doesn't expose anything the domain's own HTML doesn't already).
 *
 * Exists so a stalled, non-technical signup can be pointed here directly
 * (email, WhatsApp, forwarded to a developer) without hitting a login wall
 * first — the single biggest friction point for someone who "freezes at a
 * block of JavaScript".
 */
class PublicInstallGuideController extends Controller
{
    /** GET /install-guide/{token} */
    public function show(string $token): JsonResponse
    {
        $domain = $this->findByToken($token);
        if (!$domain) {
            return $this->error('Not found.', 404);
        }

        return $this->success([
            'domain' => $domain->domain,
            'script_token' => $domain->script_token,
            'verified' => $domain->isScriptVerified(),
        ]);
    }

    /** GET /install-guide/{token}/verify */
    public function verify(string $token): JsonResponse
    {
        $domain = $this->findByToken($token);
        if (!$domain) {
            return $this->error('Not found.', 404);
        }

        $verified = cache()->has("script_verified:{$domain->script_token}");
        if ($verified) {
            $domain->update(['script_verified_at' => now()]);
            cache()->forget("script_verified:{$domain->script_token}");
        }

        return $this->success([
            'verified' => $verified || $domain->isScriptVerified(),
        ]);
    }

    private function findByToken(string $token): ?Domain
    {
        return Domain::where('script_token', $token)->first();
    }
}
