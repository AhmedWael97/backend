<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotMessage extends Model
{
    protected $fillable = [
        'session_id',
        'role',
        'content',
        'tokens_used',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatbotSession::class, 'session_id');
    }
}
