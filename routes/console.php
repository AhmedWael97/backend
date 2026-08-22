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
Schedule::command('eye:send-trial-tips')->dailyAt('09:30');
// Check-up: domain added but zero events (snippet likely missing). Once/user, off-peak.
Schedule::command('eye:send-domain-checkup')->dailyAt('10:00');
Schedule::command('eye:suggest-connect-checked-domains')->dailyAt('11:00');
Schedule::command('eye:cleanup-stale-domains')->dailyAt('03:30');
// SEO content: 4 posts/day (1500-2200 words each), topics from a curated
// rotation + real Serper keyword research, each with one guaranteed-valid
// internal link (see command for the topic->URL map / discovery logic).
Schedule::command('eye:generate-blog-posts --count=4')->dailyAt('06:00')->emailOutputOnFailure('info@senueg.com');
// Real Google positions for every tracked keyword, via Serper.dev (no-ops if SERPER_API_KEY unset).
Schedule::command('eye:fetch-seo-rankings')->dailyAt('05:00');
// AI-visibility tracking: does EYE get mentioned when Claude answers real buyer questions. Slow-moving signal, weekly is enough.
Schedule::command('eye:check-ai-visibility')->weeklyOn(1, '07:00');
