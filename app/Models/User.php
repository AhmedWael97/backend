<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'api_key',
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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
