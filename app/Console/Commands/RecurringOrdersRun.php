<?php

namespace App\Console\Commands;

use App\Services\Shop\RecurringOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Orders this month's products for every multi-month order once the monthly
 * cutoff (19th, 15:00) has passed. Runs hourly; a month is never ordered
 * twice. --at simulates another moment in Europe/Skopje time.
 */
class RecurringOrdersRun extends Command
{
    protected $signature = 'shop:recurring-orders {--at= : Run as if it were this moment (Europe/Skopje time)}';

    protected $description = 'Place this month\'s order for every active multi-month order';

    public function handle(RecurringOrderService $recurring): int
    {
        $at = $this->option('at')
            ? Carbon::parse($this->option('at'), config('shop.weekly.timezone'))->utc()
            : now();

        if ($this->option('at')) {
            Carbon::setTestNow($at);
        }

        $result = $recurring->runDue($at);

        $this->info("Created {$result['created']} order(s), skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
