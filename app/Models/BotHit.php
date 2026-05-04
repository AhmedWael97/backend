<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BotHit extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain_id', 'date', 'hits'];

    /**
     * Atomically increment today's bot-hit counter for a domain.
     * Uses PostgreSQL "ON CONFLICT … DO UPDATE" to avoid a race condition.
     */
    public static function incrementToday(int $domainId): void
    {
        DB::statement(
            'INSERT INTO bot_hits (domain_id, date, hits)
             VALUES (?, ?, 1)
             ON CONFLICT (domain_id, date)
             DO UPDATE SET hits = bot_hits.hits + 1',
            [$domainId, now()->toDateString()]
        );
    }
}
