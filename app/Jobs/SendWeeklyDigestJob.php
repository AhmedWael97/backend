<?php

namespace App\Jobs;

use App\Mail\WeeklyDigestMail;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendWeeklyDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(ClickHouseService $clickhouse): void
    {
        $user = User::with('domains')->find($this->userId);

        if (!$user || $user->status !== 'active') {
            return;
        }

        $domainIds = $user->domains()->pluck('id')->join(', ');
        if (empty($domainIds)) {
            return;
        }

        $from = now()->subDays(7)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');

        $stats = $clickhouse->select("
            SELECT
                uniq(visitor_id) AS visitors,
                count()          AS sessions,
                any(country)     AS top_country
            FROM sessions
            WHERE domain_id IN ({$domainIds})
              AND started_at >= '{$from}' AND started_at < '{$to}'
        ")[0] ?? [];

        $topPage = $clickhouse->select("
            SELECT url, count() AS c FROM events
            WHERE domain_id IN ({$domainIds})
              AND ts >= '{$from}' AND ts < '{$to}'
              AND type = 'pageview'
            GROUP BY url ORDER BY c DESC LIMIT 1
        ")[0]['url'] ?? '';

        Mail::to($user->email)->queue(new WeeklyDigestMail($user, [
            'visitors' => $stats['visitors'] ?? 0,
            'sessions' => $stats['sessions'] ?? 0,
            'top_country' => $stats['top_country'] ?? '',
            'top_page' => $topPage,
        ]));
    }
}
