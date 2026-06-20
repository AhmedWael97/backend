<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Experiment extends Model
{
    protected $fillable = [
        'domain_id', 'key', 'name', 'variants', 'is_active',
        'type', 'target_url', 'goal_type', 'goal_value', 'status',
    ];

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

    public function variations(): HasMany
    {
        return $this->hasMany(ExperimentVariation::class)->orderBy('sort');
    }
}
