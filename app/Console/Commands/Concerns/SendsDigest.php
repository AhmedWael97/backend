<?php

namespace App\Console\Commands\Concerns;

use App\Models\Domain;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\InsightEngine;
use Illuminate\Support\Facades\Mail;

/**
 * Shared body for the weekly/daily digest commands: pull traffic stats + the
 * top InsightEngine findings across every domain the user can access, and
 * queue the given mailable. Only the user-selection query and window differ
 * between weekly (opt-out) and daily (opt-in).
 */
trait SendsDigest
{
    private function sendDigestTo(User $user, int $days, ClickHouseService $clickhouse, InsightEngine $engine, string $mailClass): bool
    {
        // Centralised access: an org member's digest covers domains they were
        // granted, not just ones they personally own.
        $domains = Domain::accessibleBy($user)->get();
        if ($domains->isEmpty()) {
            return false;
        }
        $domainIds = $domains->pluck('id')->implode(', ');

        $from = now()->subDays($days)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');

        $stats = $clickhouse->select("
            SELECT uniq(visitor_id) AS visitors, count() AS sessions, any(country) AS top_country
            FROM sessions WHERE domain_id IN ({$domainIds}) AND started_at >= '{$from}' AND started_at < '{$to}'
        ")[0] ?? [];

        $topPage = $clickhouse->select("
            SELECT url, count() AS c FROM events
            WHERE domain_id IN ({$domainIds}) AND ts >= '{$from}' AND ts < '{$to}' AND type = 'pageview'
            GROUP BY url ORDER BY c DESC LIMIT 1
        ")[0]['url'] ?? '';

        $findings = [];
        foreach ($domains as $domain) {
            foreach ($engine->overview($domain->id) as $f) {
                $f['domain'] = $domain->domain;
                $findings[] = $f;
            }
        }
        usort($findings, fn ($a, $b) => $b['impact'] <=> $a['impact']);
        $findings = array_slice($findings, 0, 3);

        Mail::to($user->email)->queue(new $mailClass($user, [
            'visitors' => $stats['visitors'] ?? 0,
            'sessions' => $stats['sessions'] ?? 0,
            'top_country' => $stats['top_country'] ?? '',
            'top_page' => $topPage,
            'findings' => $findings,
        ]));

        $this->line("Queued digest for {$user->email} ({$domains->count()} domain(s), " . count($findings) . ' finding(s))');

        return true;
    }
}
