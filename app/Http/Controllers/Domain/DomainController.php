<?php

namespace App\Http\Controllers\Domain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EmailController;
use App\Http\Requests\Domain\StoreExclusionRequest;
use App\Http\Requests\Domain\StoreDomainRequest;
use App\Http\Requests\Domain\UpdateDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Models\DomainExclusion;
use App\Models\User;
use App\Mail\BrandedEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
            // The shared sandbox domain is reachable directly by id (accessibleBy
            // allows it), but stays out of the "my domains" management list —
            // it's surfaced separately, read-only, via GET /demo/domain.
            $domains = Domain::accessibleBy($user)
                ->where('is_demo', false)
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

        // ~90% of signups arrive on a phone, where pasting a <script> tag into a
        // site's <head> is not possible — which is why domains were being added
        // and then never installed. Put the snippet in their inbox right away so
        // the install can happen later, at a desktop, without having to find this
        // page again. Best-effort: a mail failure must not fail domain creation.
        $this->mailInstallInstructions($user, $domain);

        return $this->success((new DomainResource($domain))->resolve(), 201);
    }

    /**
     * Email the install snippet + one-click install link for this domain to the
     * signed-in user. Deliberately sends only to the account's own address —
     * never a user-supplied one — so it cannot be used to mail strangers.
     */
    public function sendInstallEmail(Request $request, Domain $domain): JsonResponse
    {
        $user = $request->user();

        if (!$user->canAccessDomain($domain)) {
            return $this->error('Not found.', 404);
        }

        if (!$this->mailInstallInstructions($user, $domain)) {
            return $this->error('Could not send the email right now. Please try again.', 502);
        }

        return $this->success(['sent_to' => $user->email]);
    }

    /** @return bool whether the message was handed to the mailer */
    private function mailInstallInstructions(User $user, Domain $domain): bool
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $installUrl = "{$appUrl}/en/install/{$domain->script_token}";
        $snippet = htmlspecialchars($domain->installSnippet(), ENT_QUOTES, 'UTF-8');
        $codeStyle = 'background:#0A0A0A;color:#E5E5E5;padding:14px;border-radius:6px;'
            . 'font-size:12px;line-height:1.5;overflow-x:auto;white-space:pre-wrap;word-break:break-all';

        try {
            Mail::to($user->email)->queue(new BrandedEmail(
                "Install EYE on {$domain->domain}",
                [
                    'preheader' => "Your tracking snippet for {$domain->domain} — paste it once and data starts flowing.",
                    'heading' => "Here's your snippet for {$domain->domain}",
                    'lines' => [
                        'Open this email on the computer you edit your site from, then paste the code below just before the closing <strong>&lt;/head&gt;</strong> tag.',
                        "<pre style='{$codeStyle}'>{$snippet}</pre>",
                        'On WordPress or Shopify you never touch code — the install guide below has a step-by-step path for each.',
                    ],
                    'ctaText' => 'Open the install guide',
                    'ctaUrl' => $installUrl,
                    'replyNote' => "Stuck? <strong>Reply to this email</strong> and we'll install it for you.",
                    'unsubUrl' => EmailController::unsubscribeUrl($user->email),
                ]
            ));
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return true;
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
