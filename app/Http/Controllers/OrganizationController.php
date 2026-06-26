<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Agency / team management. An organization is owned by one user (who holds the
 * Agency plan) and has up to N member seats. Members are granted access to
 * specific domains via domain_access.
 */
class OrganizationController extends Controller
{
    /** GET /organization — the caller's org with members, invites, domains, limits. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $user->organization();

        if (!$org) {
            return $this->success(['organization' => null]);
        }

        $membership = $org->members()->where('user_id', $user->id)->first();
        $isAdmin = $user->id === $org->owner_user_id || ($membership?->isOwnerOrAdmin() ?? false);

        $plan = $org->owner?->activeSubscription()->with('plan')->first()?->plan;
        $seatLimit = (int) (optional($plan)->getLimit('team_members', 1) ?? 1);
        $domainLimit = (int) (optional($plan)->getLimit('domains', 1) ?? 1);

        $domains = $org->domains()->get(['id', 'domain']);

        // Members with their assigned domain ids (owner/admin implicitly see all).
        $members = $org->members()->with('user:id,name,email')->get()->map(function (OrganizationMember $m) use ($org) {
            $assigned = DB::table('domain_access')
                ->join('domains', 'domains.id', '=', 'domain_access.domain_id')
                ->where('domain_access.user_id', $m->user_id)
                ->where('domains.organization_id', $org->id)
                ->pluck('domain_access.domain_id');
            return [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'status' => $m->status,
                'domain_ids' => $assigned,
            ];
        });

        return $this->success([
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'owner_user_id' => $org->owner_user_id,
                'is_admin' => $isAdmin,
                'seat_limit' => $seatLimit,
                'seats_used' => $org->seatsUsed(),
                'domain_limit' => $domainLimit,
                'domains' => $domains,
                'members' => $members,
                'invitations' => $isAdmin
                    ? $org->invitations()->whereNull('accepted_at')->get(['id', 'email', 'role', 'created_at'])
                    : [],
            ],
        ]);
    }

    /** POST /organization — turn the caller into an agency (create org + Agency plan). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        if ($user->ownedOrganizations()->exists()) {
            return $this->error('You already have an organization.', 422);
        }

        $agency = Plan::where('slug', 'agency')->first();
        if (!$agency) {
            return $this->error('Agency plan is not available.', 422);
        }

        $org = DB::transaction(function () use ($user, $data, $agency) {
            $org = Organization::create(['name' => $data['name'], 'owner_user_id' => $user->id]);

            // Owner membership.
            OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
            ]);

            // Subscribe the owner to the (free) Agency plan — no expiry.
            Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $agency->id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => null,
                'notes' => 'Agency plan',
            ]);

            // Adopt the owner's existing personal domains into the org so they
            // can be assigned to members.
            Domain::where('user_id', $user->id)->whereNull('organization_id')
                ->update(['organization_id' => $org->id]);

            return $org;
        });

        return $this->success(['organization_id' => $org->id, 'message' => 'Agency workspace created.'], 201);
    }

    /** POST /organization/invitations — invite an employee by email (+ optional domains). */
    public function invite(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $this->adminOrg($user);
        if (!$org) {
            return $this->error('Only an organization owner/admin can invite members.', 403);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'in:admin,member'],
            'domain_ids' => ['nullable', 'array'],
            'domain_ids.*' => ['integer'],
        ]);
        $email = strtolower(trim($data['email']));
        $role = $data['role'] ?? 'member';

        // Seat limit.
        $plan = $org->owner?->activeSubscription()->with('plan')->first()?->plan;
        $seatLimit = (int) (optional($plan)->getLimit('team_members', 1) ?? 1);
        if ($seatLimit !== -1 && $org->seatsUsed() >= $seatLimit) {
            return $this->error("Your plan allows up to {$seatLimit} team members.", 422);
        }

        // Restrict assignable domains to this org's domains.
        $domainIds = $this->orgDomainIds($org, $data['domain_ids'] ?? []);

        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($existing) {
            if ($org->members()->where('user_id', $existing->id)->exists()) {
                return $this->error('That person is already a member.', 422);
            }
            DB::transaction(function () use ($org, $existing, $role, $domainIds) {
                OrganizationMember::create([
                    'organization_id' => $org->id,
                    'user_id' => $existing->id,
                    'role' => $role,
                    'status' => 'active',
                ]);
                $this->syncMemberDomains($existing->id, $org, $domainIds);
            });
            return $this->success(['message' => 'Member added.', 'status' => 'added']);
        }

        // No account yet → pending invitation; auto-accepted when they register.
        $invite = OrganizationInvitation::updateOrCreate(
            ['organization_id' => $org->id, 'email' => $email],
            ['role' => $role, 'token' => Str::random(48), 'expires_at' => now()->addDays(14), 'accepted_at' => null]
        );
        // Stash intended domain grants on the invite (applied on accept).
        if (!empty($domainIds)) {
            cache()->put("org_invite_domains:{$invite->token}", $domainIds, now()->addDays(14));
        }

        $this->sendInviteEmail($invite, $org);

        return $this->success([
            'message' => 'Invitation sent.',
            'status' => 'invited',
            'invite_url' => $this->inviteUrl($invite),
        ]);
    }

    /** POST /organization/invitations/{token}/accept — logged-in invitee joins. */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        $invite = OrganizationInvitation::where('token', $token)->whereNull('accepted_at')->first();

        if (!$invite || $invite->isExpired()) {
            return $this->error('This invitation is invalid or has expired.', 404);
        }
        if (strtolower($user->email) !== strtolower($invite->email)) {
            return $this->error('This invitation was sent to a different email address.', 403);
        }

        $org = $invite->organization;
        if (!$org->members()->where('user_id', $user->id)->exists()) {
            OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'role' => $invite->role,
                'status' => 'active',
            ]);
            $domainIds = (array) cache()->pull("org_invite_domains:{$invite->token}", []);
            if ($domainIds) {
                $this->syncMemberDomains($user->id, $org, $this->orgDomainIds($org, $domainIds));
            }
        }
        $invite->update(['accepted_at' => now()]);

        return $this->success(['message' => 'You have joined the organization.', 'organization_id' => $org->id]);
    }

    /** DELETE /organization/invitations/{id} — cancel a pending invite. */
    public function cancelInvite(Request $request, int $id): JsonResponse
    {
        $org = $this->adminOrg($request->user());
        if (!$org) {
            return $this->error('Not allowed.', 403);
        }
        $org->invitations()->whereKey($id)->delete();
        return $this->success(['message' => 'Invitation cancelled.']);
    }

    /** POST /organization/members/{userId}/domains — set a member's assigned domains. */
    public function assignDomains(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        $org = $this->adminOrg($user);
        if (!$org) {
            return $this->error('Not allowed.', 403);
        }
        $member = $org->members()->where('user_id', $userId)->first();
        if (!$member) {
            return $this->error('Member not found.', 404);
        }

        $data = $request->validate([
            'domain_ids' => ['present', 'array'],
            'domain_ids.*' => ['integer'],
        ]);
        $this->syncMemberDomains($userId, $org, $this->orgDomainIds($org, $data['domain_ids']));

        return $this->success(['message' => 'Domain access updated.']);
    }

    /** DELETE /organization/members/{userId} — remove a member (not the owner). */
    public function removeMember(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        $org = $this->adminOrg($user);
        if (!$org) {
            return $this->error('Not allowed.', 403);
        }
        if ($userId === $org->owner_user_id) {
            return $this->error('The owner cannot be removed.', 422);
        }

        DB::transaction(function () use ($org, $userId) {
            $org->members()->where('user_id', $userId)->delete();
            DB::table('domain_access')
                ->where('user_id', $userId)
                ->whereIn('domain_id', $org->domains()->pluck('id'))
                ->delete();
        });

        return $this->success(['message' => 'Member removed.']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** The org the user owns/administers, or null. */
    private function adminOrg(User $user): ?Organization
    {
        $membership = $user->organizationMemberships()->whereIn('role', ['owner', 'admin'])->first();
        return $membership?->organization ?? $user->ownedOrganizations()->first();
    }

    /** Filter the given ids down to domains that actually belong to the org. */
    private function orgDomainIds(Organization $org, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return $org->domains()->whereIn('id', $ids)->pluck('id')->all();
    }

    /** Replace a member's domain_access rows for this org's domains. */
    private function syncMemberDomains(int $userId, Organization $org, array $domainIds): void
    {
        $orgDomainIds = $org->domains()->pluck('id');
        DB::table('domain_access')->where('user_id', $userId)->whereIn('domain_id', $orgDomainIds)->delete();
        $rows = array_map(fn ($id) => [
            'domain_id' => $id,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $domainIds);
        if ($rows) {
            DB::table('domain_access')->insert($rows);
        }
    }

    private function inviteUrl(OrganizationInvitation $invite): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        return "{$base}/en/auth/accept-invite?token={$invite->token}";
    }

    private function sendInviteEmail(OrganizationInvitation $invite, Organization $org): void
    {
        try {
            $url = $this->inviteUrl($invite);
            Mail::raw(
                "You've been invited to join {$org->name} on EYE Analytics.\n\n"
                . "Accept your invitation:\n{$url}\n\n"
                . "If you don't have an account yet, register with this email ({$invite->email}) and you'll be added automatically.",
                function ($m) use ($invite, $org) {
                    $m->to($invite->email)->subject("You're invited to {$org->name} on EYE");
                }
            );
        } catch (\Throwable $e) {
            report($e); // best-effort; the owner can still share the invite_url
        }
    }
}
