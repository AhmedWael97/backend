<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Experiment extends Model
{
    protected $fillable = ['domain_id', 'key', 'name', 'variants', 'is_active'];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
