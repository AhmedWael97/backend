<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSuggestion extends Model
{
    protected $fillable = ['domain_id', 'text', 'category', 'priority', 'is_dismissed'];

    protected function casts(): array
    {
        return ['is_dismissed' => 'boolean'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
