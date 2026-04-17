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
Schedule::command('eye:cleanup-events')->dailyAt('02:00');
Schedule::command('eye:cleanup-exports')->hourly();
Schedule::command('eye:cleanup-tokens')->hourly();
Schedule::command('eye:process-deletions')->everyFifteenMinutes();
Schedule::command('eye:weekly-digest')->weeklyOn(1, '08:00'); // Monday 08:00 UTC
