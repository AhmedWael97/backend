<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UxScore extends Model
{
    protected $fillable = ['domain_id', 'score', 'breakdown', 'calculated_at'];

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
