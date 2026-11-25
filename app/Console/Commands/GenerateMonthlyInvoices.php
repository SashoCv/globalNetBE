<?php

namespace App\Console\Commands;

use App\Models\ShopInvoice;
use App\Services\Shop\InvoiceMailer;
use App\Services\Shop\MonthlySchedule;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'shop:generate-monthly-invoices
                            {--from= : Period start date (default: of the last closed billing period)}
                            {--to=   : Period end date (default: of the last closed billing period)}';

    protected $description = 'By hand (shop:monthly-cycle runs it on the 21st): generate one pro-invoice per clinic aggregating all un-invoiced orders of the billing period that just closed (the 20th of last month to the 19th), and mail each to its clinic.';

    public function handle(InvoiceMailer $mailer): int
    {
        [$defaultFrom, $defaultTo] = ShopInvoice::lastClosedBillingPeriod();
        $from = $this->option('from') ?: $defaultFrom;
        $to   = $this->option('to') ?: $defaultTo;

        $this->info(now(config('shop.weekly.timezone'))->format('Y-m-d H:i') . " Генерирање месечни фактури — период: {$from} → {$to}");

        // Past the 25th a by-hand run still leaves the clinics a few days.
        $due = MonthlySchedule::forPeriodEnd($to)->paymentDue->toDateString();
        $result = ShopInvoice::generateForPeriod($from, $to, null, $due >= now()->toDateString() ? $due : null);

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
