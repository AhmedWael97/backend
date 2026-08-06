<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\QuestionnaireResponse;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * POST /api/v1/onboarding/quiz — the "get started" questionnaire's single
 * finalize step. Works two ways:
 *   - No Bearer token: creates the account from the given email (same
 *     password=null pattern as Google-created users — no password prompt in
 *     this flow) and returns a fresh token.
 *   - Bearer token present (e.g. after "Continue with Google" round-tripped
 *     through the normal OAuth flow first): reuses that user, no account
 *     created.
 * Either way: registers the entered domains, assigns a plan by domain count
 * (1 -> free, 2-5 -> pro, 6+ -> business) with the same 30-day trial every
 * signup gets, and logs the full questionnaire for the superadmin.
 */
class OnboardingQuizController extends Controller
{
    public function finalize(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();

        $rules = [
            'role' => ['required', 'in:site_owner,marketer'],
            'sites_managed' => ['required', 'integer', 'min:0', 'max:1000'],
            'languages' => ['array'],
            'languages.*' => ['string', 'max:40'],
            'features' => ['array'],
            'features.*' => ['string', 'max:60'],
            'domains' => ['array', 'max:10'],
            'domains.*.domain' => ['required', 'string', 'max:255'],
            'domains.*.seo_score' => ['nullable', 'numeric'],
            'domains.*.speed_score' => ['nullable', 'numeric'],
            'domains.*.pages_found' => ['nullable', 'integer'],
        ];
        if (!$user) {
            $rules['email'] = ['required', 'email', 'max:255'];
            $rules['name'] = ['nullable', 'string', 'max:255'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }
        $data = $validator->validated();

        $token = null;
        if (!$user) {
            $email = strtolower(trim($data['email']));
            $user = User::where('email', $email)->first();

            if ($user && !$user->isActive()) {
                return $this->error('Your account is suspended.', 403);
            }

            if (!$user) {
                $user = User::create([
                    'name' => $data['name'] ?: ucfirst(explode('@', $email)[0]),
                    'email' => $email,
                    'password' => null,
                    'api_key' => Str::random(64),
                    'locale' => 'en',
                    'timezone' => 'UTC',
                    'appearance' => 'system',
                    'role' => 'user',
                    'status' => 'active',
                    'referral_code' => User::generateReferralCode(),
                ]);
                Referral::maybeCreate($request->input('referral_code'), $user);

                if (!config('app.email_verification_enabled', false)) {
                    $user->markEmailAsVerified();
                }

                try {
                    \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\WelcomeMail($user));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $token = $user->createToken('api')->plainTextToken;
        }

        $domainInputs = collect($data['domains'] ?? [])
            ->map(fn ($d) => trim((string) $d['domain']))
            ->filter()
            ->unique()
            ->values();

        $domainCount = max($domainInputs->count(), 1);
        $planSlug = $domainCount <= 1 ? 'free' : ($domainCount <= 5 ? 'pro' : 'business');
        $plan = Plan::where('slug', $planSlug)->first();

        if ($plan) {
            $activeSub = Subscription::where('user_id', $user->id)->where('status', 'active')->first();
            if ($activeSub) {
                $activeSub->update(['plan_id' => $plan->id]);
            } else {
                Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays(30),
                    'notes' => 'Onboarding quiz — 30-day trial',
                ]);
            }
        }

        $createdDomains = [];
        foreach ($domainInputs as $host) {
            $host = preg_replace('#^https?://#i', '', $host);
            $host = rtrim(explode('/', $host)[0], '/');
            if ($host === '') {
                continue;
            }
            $existing = $user->domains()->where('domain', $host)->first();
            $domain = $existing ?: $user->domains()->create([
                'domain' => $host,
                'timezone' => 'UTC',
                'settings' => [],
                'active' => true,
            ]);
            $createdDomains[] = [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'script_token' => $domain->script_token,
            ];
        }

        QuestionnaireResponse::create([
            'user_id' => $user->id,
            'role' => $data['role'],
            'sites_managed' => $data['sites_managed'],
            'languages' => $data['languages'] ?? [],
            'features' => $data['features'] ?? [],
            'domains' => $data['domains'] ?? [],
            'plan_assigned_id' => $plan?->id,
        ]);

        return $this->success([
            'token' => $token,
            'user' => $user->refresh()->only(['id', 'name', 'email', 'role']),
            'domains' => $createdDomains,
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'slug' => $plan->slug] : null,
            'trial_ends_at' => $user->effectiveSubscription()?->current_period_end,
        ], 201);
    }
}
