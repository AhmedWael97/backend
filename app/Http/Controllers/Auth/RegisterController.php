<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    /**
     * @OA\Post(
     *   path="/api/v1/auth/register",
     *   summary="Register a new user account",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","password","password_confirmation"},
     *       @OA\Property(property="name",                  type="string",  example="John Doe"),
     *       @OA\Property(property="email",                 type="string",  format="email"),
     *       @OA\Property(property="password",              type="string",  format="password"),
     *       @OA\Property(property="password_confirmation", type="string",  format="password"),
     *       @OA\Property(property="locale",                type="string",  example="en"),
     *       @OA\Property(property="timezone",              type="string",  example="UTC")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Account created — returns token"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            // Name is optional at signup (email-first). Fall back to the email's
            // local-part so the dashboard/greeting still has something to show.
            'name' => $request->name ?: ucfirst(explode('@', (string) $request->email)[0]),
            'email' => $request->email,
            'password' => $request->password, // hashed via cast
            'api_key' => Str::random(64),
            'locale' => $request->input('locale', 'en'),
            'timezone' => $request->input('timezone', 'UTC'),
            'appearance' => 'system',
            'role' => 'user',
            'status' => 'active',
        ]);

        // Attach a 30-day free trial on the free plan. After `current_period_end`
        // the subscription is no longer "active" (see User::activeSubscription),
        // so the `subscribed` middleware blocks product features until the user
        // pays. Account/billing routes stay open so they can subscribe.
        $freePlan = Plan::where('slug', 'free')->first();
        if ($freePlan) {
            Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(30),
                'notes' => 'Free trial (30 days)',
            ]);
        }

        // Auto-join any pending org invitations addressed to this email, so an
        // invited agency employee just registers and lands inside the team.
        $this->acceptPendingInvitations($user);

        // Only dispatch verification flow when explicitly enabled.
        // This avoids SMTP/network latency (and 504s) when verification is paused.
        if (!config('app.email_verification_enabled', false)) {
            $user->markEmailAsVerified();
        } else {
            // Send the "account created — activate it" email as a QUEUED job, out of
            // the request cycle. Signup can never fail on a mail transport error.
            \App\Jobs\SendVerificationEmail::dispatch($user->id);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'user' => $user->refresh()->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'timezone', 'appearance', 'role', 'status']),
            'token' => $token,
        ], 201);
    }

    /** Join any pending organization invitations addressed to the user's email. */
    private function acceptPendingInvitations(User $user): void
    {
        $invites = \App\Models\OrganizationInvitation::whereRaw('lower(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->get();

        foreach ($invites as $invite) {
            if ($invite->expires_at && $invite->expires_at->isPast()) {
                continue;
            }
            \App\Models\OrganizationMember::firstOrCreate(
                ['organization_id' => $invite->organization_id, 'user_id' => $user->id],
                ['role' => $invite->role, 'status' => 'active'],
            );

            $domainIds = (array) cache()->pull("org_invite_domains:{$invite->token}", []);
            $orgDomainIds = \App\Models\Domain::where('organization_id', $invite->organization_id)
                ->whereIn('id', $domainIds)->pluck('id');
            foreach ($orgDomainIds as $did) {
                \Illuminate\Support\Facades\DB::table('domain_access')->updateOrInsert(
                    ['domain_id' => $did, 'user_id' => $user->id],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }

            $invite->update(['accepted_at' => now()]);
        }
    }
}
