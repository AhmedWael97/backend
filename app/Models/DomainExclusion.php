<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainExclusion extends Model
{
    use HasFactory;

    protected $fillable = ['domain_id', 'type', 'value'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
