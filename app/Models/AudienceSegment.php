<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudienceSegment extends Model
{
    protected $fillable = ['domain_id', 'name', 'description', 'rules', 'visitor_count', 'color'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
