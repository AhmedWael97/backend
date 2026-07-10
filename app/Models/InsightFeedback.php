<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightFeedback extends Model
{
    protected $fillable = ['user_id', 'domain_id', 'page', 'kind', 'helpful'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }
}
