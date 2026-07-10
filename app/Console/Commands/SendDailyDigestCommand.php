<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsDigest;
use App\Mail\DailyDigestMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\InsightEngine;
use Illuminate\Console\Command;

class SendDailyDigestCommand extends Command
{
    use SendsDigest;

    protected $signature = 'eye:daily-digest';
    protected $description = 'Send daily analytics digest emails to users who opted in, with top InsightEngine findings.';

    public function handle(ClickHouseService $clickhouse, InsightEngine $engine): void
    {
        // Opt-in: daily is a faster cadence than most users want, so unlike the
        // weekly digest this requires an explicit enabled row.
        $optedIn = NotificationPreference::where('type', 'daily_digest')->where('email', true)->pluck('user_id');
        if ($optedIn->isEmpty()) {
            $this->line('No users opted into the daily digest.');

            return;
        }

        $users = User::where('status', 'active')->whereIn('id', $optedIn)->get();

        foreach ($users as $user) {
            try {
                $this->sendDigestTo($user, 1, $clickhouse, $engine, DailyDigestMail::class);
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: " . $e->getMessage());
            }
        }
    }
}
