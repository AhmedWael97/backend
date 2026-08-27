<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\CapturesAcquisition;
use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class FacebookController extends Controller
{
    use CapturesAcquisition;

    public function redirect(Request $request)
    {
        $redirect = $request->input('redirect') ?: config('app.frontend_url') . '/en/auth/callback';
        $state = base64_encode($redirect);

        return Socialite::driver('facebook')
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    public function callback(Request $request)
    {
        try {
            $fbUser = Socialite::driver('facebook')->stateless()->user();
        } catch (\Throwable $e) {
            return $this->error('Facebook authentication failed.', 400);
        }

        $redirect = $this->decodeRedirect($request->input('state'));
        $facebookId = $fbUser->getId();
        $email = $fbUser->getEmail();

        if (!$email) {
            return $this->error('Facebook did not provide an email address. Grant email permission and try again.', 400);
        }

        $user = User::where('facebook_id', $facebookId)
            ->orWhere('email', $email)
            ->first();

        if ($user && !$user->isActive()) {
            return $this->error('Your account is suspended.', 403);
        }

        if (!$user) {
            // Referral code + first-touch utm/click-id ride inside the redirect
            // URL's query string, which survives the OAuth trip via `state`.
            parse_str((string) parse_url($redirect, PHP_URL_QUERY), $redirectParams);
            $user = $this->createUserFromFacebook(
                $fbUser,
                $redirectParams['ref'] ?? null,
                $this->acquisitionAttributes($redirectParams)
            );
        } elseif (!$user->facebook_id) {
            $user->facebook_id = $facebookId;
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
     * Facebook App Dashboard → Settings → Advanced → "Deauthorize Callback URL".
     * Called server-to-server when a user removes the app from their FB
     * settings. Just drop the facebook_id link — the EYE account (and any
     * other login method on it) stays intact.
     */
    public function deauthorize(Request $request)
    {
        $data = $this->parseSignedRequest((string) $request->input('signed_request', ''));
        if ($data && !empty($data['user_id'])) {
            User::where('facebook_id', $data['user_id'])->update(['facebook_id' => null]);
        }

        return response('', 200);
    }

    /**
     * Facebook App Dashboard → Settings → Advanced → "Data Deletion Request URL".
     * Must respond with {url, confirmation_code} per Facebook's spec — shown
     * to the user so they can check the request's status.
     */
    public function dataDeletion(Request $request)
    {
        $data = $this->parseSignedRequest((string) $request->input('signed_request', ''));
        $code = Str::random(20);

        if ($data && !empty($data['user_id'])) {
            User::where('facebook_id', $data['user_id'])->update(['facebook_id' => null]);
        }

        return response()->json([
            'url' => config('app.frontend_url') . '/en/auth/facebook-data-deletion?code=' . $code,
            'confirmation_code' => $code,
        ]);
    }

    /**
     * Verifies + decodes Facebook's base64url signed_request (HMAC-SHA256
     * over the payload, keyed with the app secret). Returns null on any
     * malformed input or signature mismatch.
     */
    private function parseSignedRequest(string $signedRequest): ?array
    {
        if (!str_contains($signedRequest, '.')) {
            return null;
        }
        [$encodedSig, $payload] = explode('.', $signedRequest, 2);

        $sig = $this->base64UrlDecode($encodedSig);
        $data = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($data) || ($data['algorithm'] ?? '') !== 'HMAC-SHA256') {
            return null;
        }

        $secret = (string) config('services.facebook.client_secret');
        $expectedSig = hash_hmac('sha256', $payload, $secret, true);
        if (!$secret || !hash_equals($expectedSig, $sig)) {
            return null;
        }

        return $data;
    }

    private function base64UrlDecode(string $input): string
    {
        return base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4));
    }

    private function createUserFromFacebook($fbUser, ?string $referralCode = null, array $acquisition = []): User
    {
        return $this->createFacebookUser(
            $fbUser->getId(),
            $fbUser->getEmail(),
            $fbUser->getName(),
            $referralCode,
            $acquisition
        );
    }

    private function createFacebookUser(
        string $facebookId,
        string $email,
        ?string $name,
        ?string $referralCode = null,
        array $acquisition = []
    ): User {
        $name = $name ?: ucfirst(explode('@', $email)[0]);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => null,
            'facebook_id' => $facebookId,
            'api_key' => Str::random(64),
            'locale' => 'en',
            'timezone' => 'UTC',
            'appearance' => 'system',
            'role' => 'user',
            'status' => 'active',
            'referral_code' => User::generateReferralCode(),
        ] + $acquisition);

        Referral::maybeCreate($referralCode, $user);
        $this->createTrialSubscription($user);
        $this->acceptPendingInvitations($user);

        if (!config('app.email_verification_enabled', false)) {
            $user->markEmailAsVerified();
        }

        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\WelcomeMail($user));
        } catch (\Throwable $e) {
            report($e);
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
