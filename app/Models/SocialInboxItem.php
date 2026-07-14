<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialInboxItem extends Model
{
    protected $fillable = [
        'user_id', 'platform', 'item_type', 'external_id', 'author_name',
        'author_handle', 'message', 'page_url', 'status', 'draft_reply',
    ];
}
