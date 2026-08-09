<?php

namespace App\Console\Commands;

use App\Models\ShopInvoice;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'shop:generate-monthly-invoices
                            {--from= : Period start date (default: 1st of current month)}
                            {--to=   : Period end date (default: 19th of current month)}';

    protected $description = 'Auto-generate one pro-invoice per clinic aggregating all un-invoiced orders placed from the 1st to the 19th of the current month.';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->startOfMonth()->toDateString();
        $to   = $this->option('to') ?: now()->startOfMonth()->addDays(18)->toDateString(); // the 19th

        $this->info("Генерирање месечни фактури — период: {$from} → {$to}");

        $result = ShopInvoice::generateForPeriod($from, $to);

        if ($result['orders_count'] === 0) {
            $this->warn('Нема нефактурирани нарачки во овој период.');
            return 0;
        }

        $this->info($result['invoices']->count() . ' фактура(и) генерирани за ' . $result['orders_count'] . ' нарачки.');

        return 0;
    }
}
