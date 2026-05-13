<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'payment_method_id',
        'status',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * A subscription is only truly "active" if its status flag says so AND
     * the current_period_end hasn't elapsed. Without the date guard, an
     * expired paid plan would keep its limits forever.
     */
    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if (!$this->current_period_end) {
            return true;
        }
        return $this->current_period_end->isFuture();
    }

    /**
     * Query scope: subscriptions that are currently active for the user
     * (status='active' AND not past period end).
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>', now());
            });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
