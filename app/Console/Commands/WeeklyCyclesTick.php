<?php

namespace App\Console\Commands;

use App\Services\Shop\WeeklyCycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Moves the weekly group-order cycles past whatever deadline has passed.
 * Runs every minute from the scheduler; --at simulates another moment, given
 * in the cycle timezone (Europe/Skopje), e.g. --at="2026-10-12 12:01".
 */
class WeeklyCyclesTick extends Command
{
    protected $signature = 'shop:weekly-cycles {--at= : Run as if it were this moment (Europe/Skopje time)}';

    protected $description = 'Advance weekly group-order cycles: close, auto-approve discount, remind, close payment';

    public function handle(WeeklyCycleService $weekly): int
    {
        $at = $this->option('at')
            ? Carbon::parse($this->option('at'), config('shop.weekly.timezone'))->utc()
            : now();

        if ($this->option('at')) {
            // Mail, notifications and timestamps written during the run use now().
            Carbon::setTestNow($at);
        }

        $done = $weekly->tick($at);

        foreach ($done as $line) {
            $this->info($line);
        }

        return self::SUCCESS;
    }
}
