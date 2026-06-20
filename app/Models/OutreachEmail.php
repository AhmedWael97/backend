<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutreachEmail extends Model
{
    protected $fillable = [
        'user_id', 'lead_id', 'to_email', 'subject', 'body', 'status', 'unsubscribe_token', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
