<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportChat extends Model
{
    protected $fillable = [
        'user_id', 'status', 'last_message_at', 'unread_for_admin', 'unread_for_user',
        'guest_token', 'guest_name', 'guest_email',
    ];

    /** Guests have no account — fall back to the name/email they typed. */
    public function displayName(): string
    {
        return $this->user?->name ?: ($this->guest_name ?: 'Guest');
    }

    public function displayEmail(): string
    {
        return $this->user?->email ?: ($this->guest_email ?: '');
    }

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'chat_id');
    }
}
