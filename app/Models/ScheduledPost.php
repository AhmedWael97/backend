<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledPost extends Model
{
    protected $fillable = [
        'user_id', 'platform', 'language', 'prompt', 'content',
        'image_url', 'video_url', 'scheduled_at', 'status',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }
}
