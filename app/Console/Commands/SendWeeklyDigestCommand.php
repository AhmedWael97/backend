<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsDigest;
use App\Mail\WeeklyDigestMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\InsightEngine;
use Illuminate\Console\Command;

class SendWeeklyDigestCommand extends Command
{
    use SendsDigest;

    protected $signature = 'eye:weekly-digest';
    protected $description = 'Send weekly analytics digest emails, with top InsightEngine findings.';

    public function handle(ClickHouseService $clickhouse, InsightEngine $engine): void
    {
        // Opt-out, not opt-in: the settings page shows this toggle on by
        // default, so "no preference row yet" must mean "still enabled" —
        // an opt-in query left this dead (no code ever seeded the row).
        $optedOut = NotificationPreference::where('type', 'weekly_digest')->where('email', false)->pluck('user_id');

        $users = User::where('status', 'active')->whereNotIn('id', $optedOut)->get();

        foreach ($users as $user) {
            try {
                $this->sendDigestTo($user, 7, $clickhouse, $engine, WeeklyDigestMail::class);
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: " . $e->getMessage());
            }
        }
    }
}
