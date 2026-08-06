<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionnaireResponse extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'role',
        'sites_managed',
        'languages',
        'features',
        'domains',
        'plan_assigned_id',
    ];

    protected $casts = [
        'languages' => 'array',
        'features' => 'array',
        'domains' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planAssigned(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_assigned_id');
    }
}
