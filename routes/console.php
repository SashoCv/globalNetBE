<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// What the less frequent jobs print goes here, one timestamped run after the
// other, so it is easy to see that cron runs them and what they did.
$schedulerLog = storage_path('logs/scheduler.log');

// Monthly group orders — one order per vendor on the 20th, invoices on the 21st
// at 12:00, a reminder before the 25th, vendor payables on the 26th. The dates
// move for non-working days, so it runs often and every step is idempotent.
Schedule::command('shop:monthly-cycle')->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo($schedulerLog);

// Multi-month orders — once the monthly reservations close (end of the 19th),
// each one orders the month's products. Hourly and idempotent, so a missed run
// catches up.
Schedule::command('shop:recurring-orders')->hourlyAt(5)
    ->withoutOverlapping()
    ->appendOutputTo($schedulerLog);

// Weekly group orders — closes the week at Mon 12:00 and sends it to the
// vendors, opens payment at Tue 10:00 if the admin has not approved a discount,
// reminds at 12:00 and cancels unpaid orders at 14:00. Every step is idempotent,
// so a missed minute just catches up.
Schedule::command('shop:weekly-cycles')->everyMinute()
    ->withoutOverlapping();
