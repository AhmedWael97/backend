<?php

namespace App\Http\Controllers\Domain;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;

/**
 * Sandbox mode: one shared, real, seeded domain any logged-in user can
 * switch into (same idea as a payment gateway's test environment). Kept
 * out of the normal domain list/CRUD entirely — this is the only entry
 * point, and it's read-only by construction (canManageDomain is never
 * granted for is_demo domains).
 */
class DemoDomainController extends Controller
{
    /** GET /demo/domain */
    public function show(): JsonResponse
    {
        $domain = Domain::where('is_demo', true)->first();

        if (!$domain) {
            return $this->error('Demo sandbox is not set up yet.', 404);
        }

        return $this->success([
            'id' => $domain->id,
            'domain' => $domain->domain,
        ]);
    }
}
