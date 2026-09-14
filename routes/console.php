<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Upstream lists change constantly; a weekly refresh keeps the county's
// enforcement current without an officer having to remember.
Schedule::command('safernet:sync-blocklists')
    ->weeklyOn(0, '02:30')
    ->withoutOverlapping()
    ->onOneServer();

// A device that stops checking in must stop counting as protected. The console
// derives this when it reads, so this only settles the stored column for
// reports and exports that query it directly.
Schedule::command('safernet:expire-stale-components')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Blocklists only know what was published before a site existed. This is how
// real learner traffic closes that gap: unknown domains are classified once and
// queued for a director to decide.
Schedule::command('safernet:triage-domains')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
