<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiVisibilityCheck extends Model
{
    protected $fillable = ['query', 'engine', 'mentioned', 'answer', 'checked_at'];

    protected function casts(): array
    {
        return [
            'mentioned' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
