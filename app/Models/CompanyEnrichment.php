<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyEnrichment extends Model
{
    // This table has no `created_at`/`updated_at` columns (it tracks freshness via
    // `enriched_at`). With Eloquent's default timestamps on, every enrichment insert
    // threw (42703: column "updated_at" does not exist) — leaving company_enrichments
    // permanently empty, so "warm leads" never found anything.
    public $timestamps = false;

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
