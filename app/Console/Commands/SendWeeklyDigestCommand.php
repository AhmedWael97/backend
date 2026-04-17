<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendWeeklyDigestCommand extends Command
{
    protected $signature = 'eye:weekly-digest';
    protected $description = 'Send weekly analytics digest emails to opted-in users.';

    public function handle(ClickHouseService $clickhouse): void
    {
        $optedIn = NotificationPreference::where('type', 'weekly_digest')
            ->where('email', true)
            ->with('user.domains')
            ->get();

        foreach ($optedIn as $pref) {
            $user = $pref->user;
            if (!$user || $user->status !== 'active') {
                continue;
            }

            $from = now()->subDays(7)->format('Y-m-d H:i:s');
            $to = now()->format('Y-m-d H:i:s');

            $domainIds = $user->domains()->pluck('id')->join(', ');
            if (empty($domainIds)) {
                continue;
            }

            try {
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

                $this->line("Queued digest for {$user->email}");
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: " . $e->getMessage());
            }
        }
    }
}
