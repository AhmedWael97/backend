<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitemapJob extends Model
{
    protected $fillable = [
        'user_id',
        'domain_id',
        'start_url',
        'status',
        'config',
        'crawl_result',
        'analytics_result',
        'sitemap_result',
        'ai_analysis',
        'sitemap_xml',
        'pages_crawled',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'config' => 'array',
        'crawl_result' => 'array',
        'analytics_result' => 'array',
        'sitemap_result' => 'array',
        'ai_analysis' => 'array',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
