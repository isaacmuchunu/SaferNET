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
