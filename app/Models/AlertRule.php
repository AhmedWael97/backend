<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertRule extends Model
{
    protected $fillable = ['domain_id', 'type', 'threshold', 'channel', 'is_active'];

    protected function casts(): array
    {
        return ['threshold' => 'array', 'is_active' => 'boolean'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
