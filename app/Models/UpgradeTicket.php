<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpgradeTicket extends Model
{
    protected $fillable = [
        'user_id', 'requested_plan_id', 'subject', 'status', 'last_message_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'requested_plan_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(UpgradeTicketMessage::class)->orderBy('id');
    }
}
