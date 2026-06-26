<?php

namespace App\Http\Controllers\Domain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Domain\StoreExclusionRequest;
use App\Http\Requests\Domain\StoreDomainRequest;
use App\Http\Requests\Domain\UpdateDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Models\DomainExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $domains = Domain::with(['exclusions', 'user:id,name,email'])
                ->latest()
                ->get();
        } else {
            // Personal domains + org domains the user may access (owner/admin: all
            // org domains; member: only assigned). Centralised in the scope.
            $domains = Domain::accessibleBy($user)
                ->with('exclusions')
                ->latest()
                ->get();
        }

        return $this->success(DomainResource::collection($domains)->resolve());
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $user = $request->user();

        // Enforce domain limit for the user's CURRENTLY-ACTIVE plan (i.e.
        // status='active' AND not past period end). Falling back to the last
        // subscription regardless of state would let an expired paid plan keep
        // unlimited domains forever.
        // If the user is an org owner/admin, the domain belongs to the org and
        // counts against the org's (Agency) plan limit. Otherwise it's personal.
        $adminMembership = $user->organizationMemberships()->whereIn('role', ['owner', 'admin'])->first();
        $orgId = $adminMembership?->organization_id;

        $activePlan = $user->effectiveSubscription()?->plan;
        $limit = optional($activePlan)->getLimit('domains', 1);
        $currentCount = $orgId
            ? Domain::where('organization_id', $orgId)->count()
            : $user->domains()->count();
        if ($limit !== null && $limit !== -1 && $currentCount >= $limit) {
            return $this->error("Your plan allows up to {$limit} domain(s). Please upgrade to add more.", 422);
        }

        $dupExists = $orgId
            ? Domain::where('organization_id', $orgId)->where('domain', $request->domain)->exists()
            : $user->domains()->where('domain', $request->domain)->exists();
        if ($dupExists) {
            return $this->error('This domain is already registered.', 422);
        }

        $domain = $user->domains()->create([
            'organization_id' => $orgId,
            'domain' => $request->domain,
            'timezone' => $request->input('timezone', 'UTC'),
            'settings' => $request->input('settings', []),
            'active' => true,
        ]);

        // Mark onboarding step
        if (empty($user->onboarding['domain_added'])) {
            $onboarding = $user->onboarding ?? [];
            $onboarding['domain_added'] = true;
            $user->update(['onboarding' => $onboarding]);
        }

        return $this->success((new DomainResource($domain))->resolve(), 201);
    }

    public function show(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canAccessDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        return $this->success((new DomainResource($domain))->resolve());
    }

    public function update(UpdateDomainRequest $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canManageDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        $domain->update($request->validated());

        return $this->success((new DomainResource($domain))->resolve());
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canManageDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        $domain->delete();

        return $this->success(['message' => 'Domain deleted.']);
    }

    /**
     * POST /api/domains/{domain}/rotate-token
     * Rotate the domain script token.
     * Old token remains valid for 60 minutes (grace period).
     */
    public function rotateToken(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canManageDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        $domain->rotateToken();

        return $this->success([
            'message' => 'Token rotated. Old token valid for 60 minutes.',
            'script_token' => $domain->script_token,
            'previous_script_token' => $domain->previous_script_token,
            'token_rotated_at' => $domain->token_rotated_at,
        ]);
    }

    /**
     * GET /api/domains/{domain}/verify-script
     * Verify the tracking script is installed on the domain.
     * Checks for a beacon hit recorded in cache by the tracker.
     */
    public function verifyScript(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canAccessDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        $verified = cache()->has("script_verified:{$domain->script_token}");

        if ($verified) {
            $domain->update(['script_verified_at' => now()]);
            cache()->forget("script_verified:{$domain->script_token}");
        }

        return $this->success([
            'verified' => $verified || $domain->isScriptVerified(),
            'script_verified_at' => $domain->fresh()->script_verified_at,
        ]);
    }

    // ── Exclusions ──────────────────────────────────────────────────────────

    public function listExclusions(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canAccessDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        return $this->success($domain->exclusions()->get(['id', 'type', 'value', 'created_at']));
    }

    public function storeExclusion(StoreExclusionRequest $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canAccessDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        $data = $request->validated();

        // Accept 'pattern' as alias for 'value' (frontend compatibility)
        if (empty($data['value']) && $request->filled('pattern')) {
            $pattern = $request->input('pattern');
            $data['value'] = $pattern;
            if (empty($data['type'])) {
                $data['type'] = preg_match('/^[\d.*:\/\[\]]+$/', $pattern) ? 'ip' : 'user_agent';
            }
        }

        $exclusion = $domain->exclusions()->create($data);

        return $this->success($exclusion, 201);
    }

    public function destroyExclusion(Request $request, Domain $domain, DomainExclusion $exclusion): JsonResponse
    {
        $user = $request->user();
        $ownsDomain = $user->canAccessDomain($domain);

        if (!$ownsDomain || $exclusion->domain_id !== $domain->id) {
            return $this->error('Not found.', 404);
        }

        $exclusion->delete();

        return $this->success(['message' => 'Exclusion removed.']);
    }
}
