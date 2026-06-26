<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['name', 'owner_user_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** The subscription that governs this org = the owner's active subscription. */
    public function activeSubscription()
    {
        return $this->owner?->activeSubscription()->with('plan')->first();
    }

    /** Count of seats currently used (active members + pending invites). */
    public function seatsUsed(): int
    {
        return $this->members()->count() + $this->invitations()->whereNull('accepted_at')->count();
    }
}
