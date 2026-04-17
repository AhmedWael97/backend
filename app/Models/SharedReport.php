<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedReport extends Model
{
    protected $fillable = ['domain_id', 'user_id', 'token', 'label', 'allowed_pages', 'expires_at'];

    protected function casts(): array
    {
        return ['allowed_pages' => 'array', 'expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->token)) {
                $model->token = bin2hex(random_bytes(32));
            }
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
