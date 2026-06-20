<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = [
        'user_id', 'domain_id', 'company', 'website', 'contact_name', 'email',
        'source', 'status', 'score', 'notes', 'last_contacted_at',
    ];

    protected function casts(): array
    {
        return ['last_contacted_at' => 'datetime', 'score' => 'integer'];
    }
}
