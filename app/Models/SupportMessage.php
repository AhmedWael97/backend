<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $fillable = ['chat_id', 'sender_user_id', 'is_admin', 'body'];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(SupportChat::class, 'chat_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
