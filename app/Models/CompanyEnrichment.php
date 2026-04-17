<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyEnrichment extends Model
{
    protected $fillable = [
        'ip_hash',
        'company_name',
        'company_domain',
        'industry',
        'employee_range',
        'country',
        'raw',
        'enriched_at',
    ];

    protected function casts(): array
    {
        return ['raw' => 'array', 'enriched_at' => 'datetime'];
    }
}
