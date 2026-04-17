<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $fillable = ['domain_id', 'name', 'description'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PipelineStep::class)->orderBy('order');
    }
}
