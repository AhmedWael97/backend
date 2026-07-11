<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    protected $fillable = [
        'code', 'campaign_name', 'discount_type', 'discount_value',
        'max_uses', 'used_count', 'expires_at', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /** Null = valid; otherwise a user-facing reason it can't be used. */
    public function invalidReason(int $userId): ?string
    {
        if (!$this->is_active) {
            return 'This code is no longer active.';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'This code has expired.';
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'This code has reached its usage limit.';
        }
        if ($this->redemptions()->where('user_id', $userId)->exists()) {
            return 'You have already used this code.';
        }

        return null;
    }

    /** Discount amount in USD for a given base USD price — never below 0. */
    public function discountUsd(float $priceUsd): float
    {
        $discount = $this->discount_type === 'percent'
            ? $priceUsd * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        return round(min($discount, $priceUsd), 2);
    }
}
