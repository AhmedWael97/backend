<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionReplay extends Model
{
    protected $fillable = [
        'domain_id',
        'session_id',
        'visitor_id',
        'start_url',
        'duration_seconds',
        'event_count',
        'has_snapshot',
        'reason',
        'score',
        'size_bytes',
        'status',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'has_snapshot' => 'boolean'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
