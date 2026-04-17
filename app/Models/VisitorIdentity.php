<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorIdentity extends Model
{
    protected $fillable = ['domain_id', 'visitor_id', 'external_id', 'traits', 'first_identified_at'];

    protected function casts(): array
    {
        return ['traits' => 'array', 'first_identified_at' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
