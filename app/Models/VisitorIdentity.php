<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorIdentity extends Model
{
    // This table has no `created_at` column (it uses `first_identified_at` for
    // that, plus a standard `updated_at`). Without this, Eloquent tries to write
    // `created_at` on insert and every identify upsert throws (42703) — leaving
    // visitor_identities empty. Keep `updated_at` auto-managed, skip created_at.
    const CREATED_AT = null;

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
