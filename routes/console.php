<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly procurement dispatch — every Monday at 07:00
Schedule::command('shop:weekly-dispatch')->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->runInBackground();
