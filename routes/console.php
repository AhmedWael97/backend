<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| EYE Scheduled Commands
|--------------------------------------------------------------------------
*/
Schedule::command('eye:analyze')->hourly();
Schedule::command('eye:check-alerts')->everyFifteenMinutes();
Schedule::command('eye:push-critical-insights')->everyThirtyMinutes();
Schedule::command('eye:process-referral-rewards')->everyFifteenMinutes();
Schedule::command('eye:cleanup-events')->dailyAt('02:00');
Schedule::command('eye:cleanup-exports')->hourly();
Schedule::command('eye:cleanup-tokens')->hourly();
Schedule::command('eye:process-deletions')->everyFifteenMinutes();
Schedule::command('eye:weekly-digest')->weeklyOn(1, '08:00'); // Monday 08:00 UTC
Schedule::command('eye:daily-digest')->dailyAt('07:30');
Schedule::command('subscriptions:expire')->dailyAt('00:05');
Schedule::command('eye:send-onboarding-reminders')->hourly();
Schedule::command('eye:nudge-abandoned-checkouts')->everySixHours();
Schedule::command('eye:send-trial-ending-reminders')->dailyAt('09:00');
// Check-up: domain added but zero events (snippet likely missing). Once/user, off-peak.
Schedule::command('eye:send-domain-checkup')->dailyAt('10:00');
