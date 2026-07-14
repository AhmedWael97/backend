<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'api_key',
        'role',
        'status',
        'timezone',
        'locale',
        'appearance',
        'onboarding',
        'totp_secret',
        'totp_enabled',
        'totp_last_used_at',
        'ai_tokens',
        'ai_free_used',
        'referral_code',
        'openai_api_key',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'api_key',
        'openai_api_key',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'totp_last_used_at' => 'datetime',
            'password' => 'hashed',
            'totp_enabled' => 'boolean',
            'ai_free_used' => 'boolean',
            'ai_tokens' => 'integer',
            'onboarding' => 'array',
            'openai_api_key' => 'encrypted',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /** Short, unique, human-shareable referral code — used by both register paths. */
    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * Returns the user's currently-effective subscription — must be `active`
     * AND not past `current_period_end`. Use this anywhere you read plan
     * limits, so an expired subscription falls through to the free defaults
     * instead of leaking paid limits forever.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>', now());
            })
            ->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ── Organizations (agency/team) ─────────────────────────────────────────

    /** Organizations this user owns. */
    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_user_id');
    }

    /** Membership rows linking this user to organizations. */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** The single org this user belongs to (owner or member), if any. */
    public function organization(): ?Organization
    {
        $membership = $this->organizationMemberships()->latest('id')->first();
        return $membership?->organization
            ?? $this->ownedOrganizations()->latest('id')->first();
    }

    /**
     * The subscription that actually governs this user's access. For an org
     * member, that's the org owner's subscription (the Agency plan). Falls back
     * to the user's own subscription.
     */
    public function effectiveSubscription(): ?Subscription
    {
        $own = $this->activeSubscription()->with('plan')->first();
        if ($own) {
            return $own;
        }

        $org = $this->organization();
        if ($org && $org->owner_user_id !== $this->id) {
            return $org->owner?->activeSubscription()->with('plan')->first();
        }

        return null;
    }

    /**
     * Can the user MANAGE a domain (rename, delete, rotate token)? Stricter than
     * access: only the personal owner, the org owner/admin, or a superadmin —
     * never a plain assigned member.
     */
    public function canManageDomain(Domain $domain): bool
    {
        if ($this->isSuperAdmin() || (int) $domain->user_id === (int) $this->id) {
            return true;
        }
        if ($domain->organization_id) {
            $member = $this->organizationMemberships()
                ->where('organization_id', $domain->organization_id)
                ->first();
            return $member?->isOwnerOrAdmin() ?? false;
        }
        return false;
    }

    /** Centralised single-domain access check (mirrors Domain::scopeAccessibleBy). */
    public function canAccessDomain(Domain $domain): bool
    {
        if ($this->isSuperAdmin() || (int) $domain->user_id === (int) $this->id) {
            return true;
        }

        if ($domain->organization_id) {
            $member = $this->organizationMemberships()
                ->where('organization_id', $domain->organization_id)
                ->first();
            if ($member) {
                if ($member->isOwnerOrAdmin()) {
                    return true;
                }
                return \App\Models\Domain::query()
                    ->whereKey($domain->id)
                    ->whereIn('id', fn($q) => $q->select('domain_id')->from('domain_access')->where('user_id', $this->id))
                    ->exists();
            }
        }

        return false;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function totpBackupCodes(): HasMany
    {
        return $this->hasMany(TotpBackupCode::class);
    }
}
