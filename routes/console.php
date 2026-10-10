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

// Monthly pro-invoice generation — 20th of the month at 01:00, covering orders
// placed from the 1st to the 19th (the reservation cutoff).
Schedule::command('shop:generate-monthly-invoices')->monthlyOn(20, '01:00')
    ->withoutOverlapping()
    ->runInBackground();

// Weekly group orders — closes the week at Mon 12:00, opens payment at Tue 08:00
// if the admin has not approved a discount, reminds at 12:00 and cancels unpaid
// orders at 14:00. Every step is idempotent, so a missed minute just catches up.
Schedule::command('shop:weekly-cycles')->everyMinute()
    ->withoutOverlapping();
