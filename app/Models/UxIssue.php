<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UxIssue extends Model
{
    protected $fillable = [
        'domain_id',
        'session_id',
        'visitor_id',
        'type',
        'url',
        'element_selector',
        'message',
        'stack_trace',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
