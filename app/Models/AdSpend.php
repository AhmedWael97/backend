<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSpend extends Model
{
    protected $table = 'ad_spend';

    protected $fillable = [
        'domain_id',
        'date',
        'source',
        'campaign',
        'medium',
        'spend',
        'currency',
        'clicks',
        'impressions',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'spend' => 'decimal:2',
        'clicks' => 'integer',
        'impressions' => 'integer',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
