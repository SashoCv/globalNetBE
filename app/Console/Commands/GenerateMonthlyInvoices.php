<?php

namespace App\Console\Commands;

use App\Models\ShopInvoice;
use App\Services\Shop\InvoiceMailer;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'shop:generate-monthly-invoices
                            {--from= : Period start date (default: the 20th of last month)}
                            {--to=   : Period end date (default: the 19th of this month)}';

    protected $description = 'Auto-generate one pro-invoice per clinic aggregating all un-invoiced orders of the billing period that just closed (the 20th of last month to the 19th), and mail each to its clinic.';

    public function handle(InvoiceMailer $mailer): int
    {
        [$defaultFrom, $defaultTo] = ShopInvoice::lastClosedBillingPeriod();
        $from = $this->option('from') ?: $defaultFrom;
        $to   = $this->option('to') ?: $defaultTo;

        $this->info(now(config('shop.weekly.timezone'))->format('Y-m-d H:i') . " Генерирање месечни фактури — период: {$from} → {$to}");

        $result = ShopInvoice::generateForPeriod($from, $to);

        if ($result['orders_count'] === 0) {
            $this->warn('Нема нефактурирани нарачки во овој период.');
            return 0;
        }

        foreach ($result['invoices'] as $invoice) {
            $mailer->issued($invoice);
        }

        $this->info($result['invoices']->count() . ' фактура(и) генерирани за ' . $result['orders_count'] . ' нарачки.');

        return 0;
    }
}
