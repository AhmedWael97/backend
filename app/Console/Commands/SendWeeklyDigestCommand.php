<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\Domain;
use App\Models\NotificationPreference;
use App\Services\ClickHouseService;
use App\Services\InsightEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendWeeklyDigestCommand extends Command
{
    protected $signature = 'eye:weekly-digest';
    protected $description = 'Send weekly analytics digest emails to opted-in users, with top InsightEngine findings.';

    public function handle(ClickHouseService $clickhouse, InsightEngine $engine): void
    {
        $optedIn = NotificationPreference::where('type', 'weekly_digest')
            ->where('email', true)
            ->with('user')
            ->get();

        foreach ($optedIn as $pref) {
            $user = $pref->user;
            if (!$user || $user->status !== 'active') {
                continue;
            }

            // Centralised access: an org member's digest covers domains they were
            // granted, not just ones they personally own.
            $domains = Domain::accessibleBy($user)->get();
            if ($domains->isEmpty()) {
                continue;
            }
            $domainIds = $domains->pluck('id')->implode(', ');

            $from = now()->subDays(7)->format('Y-m-d H:i:s');
            $to = now()->format('Y-m-d H:i:s');

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

                // Deterministic findings across every accessible domain, tagged
                // with the domain they came from, worst-impact first overall.
                $findings = [];
                foreach ($domains as $domain) {
                    foreach ($engine->overview($domain->id) as $f) {
                        $f['domain'] = $domain->domain;
                        $findings[] = $f;
                    }
                }
                usort($findings, fn ($a, $b) => $b['impact'] <=> $a['impact']);
                $findings = array_slice($findings, 0, 3);

                Mail::to($user->email)->queue(new WeeklyDigestMail($user, [
                    'visitors' => $stats['visitors'] ?? 0,
                    'sessions' => $stats['sessions'] ?? 0,
                    'top_country' => $stats['top_country'] ?? '',
                    'top_page' => $topPage,
                    'findings' => $findings,
                ]));

                $this->line("Queued digest for {$user->email} ({$domains->count()} domain(s), " . count($findings) . ' finding(s))');
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: " . $e->getMessage());
            }
        }
    }
}
