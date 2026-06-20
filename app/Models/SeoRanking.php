<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoRanking extends Model
{
    protected $fillable = ['domain_id', 'keyword', 'date', 'position', 'url'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'position' => 'integer'];
    }
}
