<?php

namespace App\Console\Commands;

use App\Services\Shop\MonthlyCycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Moves the monthly group order past whatever deadline has passed: vendor
 * orders, invoices, reminders, vendor payables. --at simulates another moment
 * in Europe/Skopje time.
 */
class MonthlyCycleTick extends Command
{
    protected $signature = 'shop:monthly-cycle {--at= : Run as if it were this moment (Europe/Skopje time)}';

    protected $description = 'Advance the monthly group order: vendor orders, invoices, reminders, vendor payables';

    public function handle(MonthlyCycleService $monthly): int
    {
        $tz = config('shop.weekly.timezone');
        $at = $this->option('at') ? Carbon::parse($this->option('at'), $tz)->utc() : now();

        if ($this->option('at')) {
            // Mail, notifications and timestamps written during the run use now().
            Carbon::setTestNow($at);
        }

        foreach ($monthly->tick($at) as $line) {
            $this->info($at->copy()->setTimezone($tz)->format('Y-m-d H:i') . ' ' . $line);
        }

        return self::SUCCESS;
    }
}
