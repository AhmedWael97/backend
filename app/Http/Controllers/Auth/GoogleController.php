<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect(Request $request)
    {
        $redirect = $request->input('redirect') ?: config('app.frontend_url') . '/en/auth/callback';
        $state = base64_encode($redirect);

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    public function callback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return $this->error('Google authentication failed.', 400);
        }

        $redirect = $this->decodeRedirect($request->input('state'));
        $googleId = $googleUser->getId();
        $email = $googleUser->getEmail();

        if (!$email) {
            return $this->error('Google did not provide an email address.', 400);
        }

        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if ($user && !$user->isActive()) {
            return $this->error('Your account is suspended.', 403);
        }

        if (!$user) {
            $user = $this->createUserFromGoogle($googleUser);
        } elseif (!$user->google_id) {
            $user->google_id = $googleId;
            $user->save();
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('api')->plainTextToken;
        $redirectUrl = $this->buildFrontendRedirect($redirect, $token);

        return redirect()->away($redirectUrl);
    }

    /**
     * Google One-Tap / GSI: the browser posts a Google ID token (JWT credential).
     * Verify it server-side via Google's tokeninfo endpoint, check the audience,
     * then sign in / register the same way as the OAuth callback. Returns a
     * bearer token JSON (no redirect) so the SPA can log in in place.
     */
    public function oneTap(Request $request)
    {
        $credential = trim((string) $request->input('credential'));
        if ($credential === '') {
            return $this->error('Missing Google credential.', 422);
        }

        try {
            $resp = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $credential]);
        } catch (\Throwable $e) {
            return $this->error('Could not verify Google credential.', 502);
        }
        if (!$resp->ok()) {
            return $this->error('Invalid Google credential.', 401);
        }
        $p = $resp->json();

        $clientId = config('services.google.client_id');
        if (!$clientId || ($p['aud'] ?? null) !== $clientId) {
            return $this->error('Google token audience mismatch.', 401);
        }
        $email = $p['email'] ?? null;
        $googleId = $p['sub'] ?? null;
        if (!$email || !$googleId) {
            return $this->error('Google did not provide an email address.', 401);
        }

        $user = User::where('google_id', $googleId)->orWhere('email', $email)->first();
        if ($user && !$user->isActive()) {
            return $this->error('Your account is suspended.', 403);
        }
        if (!$user) {
            $user = $this->createGoogleUser($googleId, $email, $p['name'] ?? null);
        } elseif (!$user->google_id) {
            $user->google_id = $googleId;
            $user->save();
        }
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success(['token' => $token, 'user' => $user->fresh()]);
    }

    private function createUserFromGoogle($googleUser): User
    {
        return $this->createGoogleUser(
            $googleUser->getId(),
            $googleUser->getEmail(),
            $googleUser->getName()
        );
    }

    private function createGoogleUser(string $googleId, string $email, ?string $name): User
    {
        $name = $name ?: ucfirst(explode('@', $email)[0]);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => null,
            'google_id' => $googleId,
            'api_key' => Str::random(64),
            'locale' => 'en',
            'timezone' => 'UTC',
            'appearance' => 'system',
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->createTrialSubscription($user);
        $this->acceptPendingInvitations($user);

        if (!config('app.email_verification_enabled', false)) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    private function createTrialSubscription(User $user): void
    {
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
    }

    private function acceptPendingInvitations(User $user): void
    {
        $invites = OrganizationInvitation::whereRaw('lower(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->get();

        foreach ($invites as $invite) {
            if ($invite->expires_at && $invite->expires_at->isPast()) {
                continue;
            }

            OrganizationMember::firstOrCreate(
                ['organization_id' => $invite->organization_id, 'user_id' => $user->id],
                ['role' => $invite->role, 'status' => 'active']
            );

            $domainIds = (array) cache()->pull("org_invite_domains:{$invite->token}", []);
            $orgDomainIds = \App\Models\Domain::where('organization_id', $invite->organization_id)
                ->whereIn('id', $domainIds)->pluck('id');

            foreach ($orgDomainIds as $did) {
                \Illuminate\Support\Facades\DB::table('domain_access')->updateOrInsert(
                    ['domain_id' => $did, 'user_id' => $user->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            $invite->update(['accepted_at' => now()]);
        }
    }

    private function decodeRedirect(?string $state): string
    {
        $default = config('app.frontend_url') . '/en/auth/callback';

        if (!$state) {
            return $default;
        }

        $redirect = base64_decode($state, true);
        if (!$redirect || !filter_var($redirect, FILTER_VALIDATE_URL)) {
            return $default;
        }

        return $redirect;
    }

    private function buildFrontendRedirect(string $redirect, string $token): string
    {
        $separator = str_contains($redirect, '?') ? '&' : '?';
        return $redirect . $separator . 'token=' . urlencode($token);
    }
}
