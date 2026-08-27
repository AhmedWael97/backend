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
        'organization_id',
        'domain',
        'script_token',
        'previous_script_token',
        'token_rotated_at',
        'script_verified_at',
        'settings',
        'active',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_demo' => 'boolean',
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Members explicitly granted access to this domain (via domain_access). */
    public function accessMembers()
    {
        return $this->belongsToMany(User::class, 'domain_access');
    }

    /**
     * Scope: domains the given user may access. Centralised so every list
     * endpoint shares one definition of access:
     *   - superadmin → all
     *   - personal owner (user_id) → yes
     *   - org owner/admin → all domains of their org(s)
     *   - org member → only domains granted via domain_access
     */
    public function scopeAccessibleBy($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $adminOrgIds = $user->organizationMemberships()
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('organization_id');

        return $query->where(function ($q) use ($user, $adminOrgIds) {
            $q->where('domains.user_id', $user->id)
                ->orWhereIn('domains.id', function ($sub) use ($user) {
                    $sub->select('domain_id')->from('domain_access')->where('user_id', $user->id);
                })
                // Sandbox: the single shared is_demo domain is readable by any
                // authenticated user, so every real page works against real
                // (seeded) data before they've connected a site of their own.
                ->orWhere('domains.is_demo', true);
            if ($adminOrgIds->isNotEmpty()) {
                $q->orWhereIn('domains.organization_id', $adminOrgIds);
            }
        });
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

    /**
     * The <script> block the user pastes into their site's <head>.
     *
     * Lives on the model because it is rendered in three places now — the
     * snippet endpoint, the install email, and the dashboard install guide —
     * and a token or tracker-URL change must not drift between them.
     */
    public function installSnippet(): string
    {
        // Both /tracker/eye.js and /api/collect are served from the frontend
        // origin, so the snippet must be absolute against that host — the
        // tracker otherwise resolves the collect endpoint against whatever
        // origin the script was loaded from.
        //
        // This is the exact tag the dashboard install guide and the
        // /install/{token} page show. It used to differ here (window.EYE_TOKEN
        // plus /tracker/eye.min.js, which 404s and carries no data-api), so
        // anything installed from GET /domains/{id}/snippet never loaded.
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return sprintf(
            '<script src="%s/tracker/eye.js" data-token="%s" data-api="%s/api/collect" async></script>',
            $base,
            $this->script_token,
            $base,
        );
    }

    public function latestUxScore(): HasOne
    {
        return $this->hasOne(UxScore::class)->latestOfMany('calculated_at');
    }
}
