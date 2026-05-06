<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotSession extends Model
{
    protected $fillable = [
        'user_id',
        'domain_id',
        'context_snapshot',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'session_id')->orderBy('id');
    }
}
