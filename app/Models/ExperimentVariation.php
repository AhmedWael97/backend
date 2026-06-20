<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentVariation extends Model
{
    protected $fillable = [
        'experiment_id', 'vkey', 'name', 'weight', 'is_control',
        'js_code', 'css_code', 'redirect_url', 'sort',
    ];

    protected function casts(): array
    {
        return ['is_control' => 'boolean', 'weight' => 'integer', 'sort' => 'integer'];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
