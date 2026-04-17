<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TotpBackupCode extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'code_hash', 'used_at', 'created_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
