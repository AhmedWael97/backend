<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorOptout extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain_id', 'visitor_id', 'opted_out_at'];

    protected $casts = ['opted_out_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (VisitorOptout $model) {
            if (empty($model->opted_out_at)) {
                $model->opted_out_at = now();
            }
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
