<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'domain',
        'script_token',
        'previous_script_token',
        'token_rotated_at',
        'script_verified_at',
        'settings',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'script_verified_at' => 'datetime',
            'token_rotated_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Domain $domain) {
            if (empty($domain->script_token)) {
                $domain->script_token = bin2hex(random_bytes(32));
            }
        });
    }

    public function isScriptVerified(): bool
    {
        return $this->script_verified_at !== null;
    }

    public function isTokenInGracePeriod(): bool
    {
        return $this->previous_script_token !== null
            && $this->token_rotated_at !== null
            && $this->token_rotated_at->diffInMinutes(now()) < 60;
    }

    public function rotateToken(): void
    {
        $this->previous_script_token = $this->script_token;
        $this->script_token = bin2hex(random_bytes(32));
        $this->token_rotated_at = now();
        $this->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(DomainExclusion::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function aiReports(): HasMany
    {
        return $this->hasMany(AiReport::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    public function latestUxScore(): HasOne
    {
        return $this->hasOne(UxScore::class)->latestOfMany('calculated_at');
    }
}
