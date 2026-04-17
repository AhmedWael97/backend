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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DomainController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $domains = $request->user()
            ->domains()
            ->with('exclusions')
            ->latest()
            ->get();

        return DomainResource::collection($domains);
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $user = $request->user();

        // Enforce domain limit for the user's active plan
        $limit = optional($user->subscription?->plan)->getLimit('domains', 1);
        if ($limit !== -1 && $user->domains()->count() >= $limit) {
            return response()->json([
                'message' => "Your plan allows up to {$limit} domain(s). Please upgrade to add more.",
            ], 422);
        }

        // Reject duplicate domain for this user
        if ($user->domains()->where('domain', $request->domain)->exists()) {
            return response()->json(['message' => 'This domain is already registered.'], 422);
        }

        $domain = $user->domains()->create([
            'domain' => strtolower(trim($request->domain)),
            'settings' => $request->input('settings', []),
            'active' => true,
        ]);

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function show(Request $request, Domain $domain): DomainResource|JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return new DomainResource($domain);
    }

    public function update(UpdateDomainRequest $request, Domain $domain): DomainResource|JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $domain->update($request->validated());

        return new DomainResource($domain);
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $domain->delete();

        return response()->json(['message' => 'Domain deleted.']);
    }

    /**
     * Rotate the tracking script token.
     * Old token remains valid for 60 minutes (grace period).
     */
    public function rotateToken(Request $request, Domain $domain): JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $domain->rotateToken();

        return response()->json([
            'message' => 'Token rotated. Old token valid for 60 minutes.',
            'script_token' => $domain->script_token,
            'previous_script_token' => $domain->previous_script_token,
            'token_rotated_at' => $domain->token_rotated_at,
        ]);
    }

    /**
     * Verify the tracking script is installed on the domain.
     * Checks for a beacon hit recorded in cache by the tracker.
     */
    public function verifyScript(Request $request, Domain $domain): JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $verified = cache()->has("script_verified:{$domain->script_token}");

        if ($verified) {
            $domain->update(['script_verified_at' => now()]);
            cache()->forget("script_verified:{$domain->script_token}");
        }

        return response()->json([
            'verified' => $verified || $domain->isScriptVerified(),
            'script_verified_at' => $domain->fresh()->script_verified_at,
        ]);
    }

    // ── Exclusions ──────────────────────────────────────────────────────────

    public function listExclusions(Request $request, Domain $domain): JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($domain->exclusions()->get(['id', 'type', 'value', 'created_at']));
    }

    public function storeExclusion(StoreExclusionRequest $request, Domain $domain): JsonResponse
    {
        if ($domain->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $data = $request->validated();

        // Accept 'pattern' as alias for 'value' (frontend compatibility)
        if (empty($data['value']) && $request->filled('pattern')) {
            $pattern = $request->input('pattern');
            $data['value'] = $pattern;
            // Auto-detect type from pattern if not provided
            if (empty($data['type'])) {
                $data['type'] = preg_match('/^[\d.*:\/\[\]]+$/', $pattern) ? 'ip' : 'user_agent';
            }
        }

        $exclusion = $domain->exclusions()->create($data);

        return response()->json($exclusion, 201);
    }

    public function destroyExclusion(Request $request, Domain $domain, DomainExclusion $exclusion): JsonResponse
    {
        if ($domain->user_id !== $request->user()->id || $exclusion->domain_id !== $domain->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $exclusion->delete();

        return response()->json(['message' => 'Exclusion removed.']);
    }
}
