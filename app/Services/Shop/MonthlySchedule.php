<?php

namespace App\Services\Shop;

use App\Support\WorkingDays;
use Illuminate\Support\Carbon;

/**
 * Deadlines of one month of the monthly group order (МОДЕЛ 03 and 04), in
 * Skopje time. Nominally: reservations to the 19th, one order to each vendor
 * on the 20th by 15:00, pro-invoice by the 21st 12:00, clinics pay to the 25th,
 * vendors are paid and delivery starts on the 26th. A deadline on a non-working
 * day moves to the next working one, and never before the step it follows.
 */
final class MonthlySchedule
{
    private function __construct(
        public readonly Carbon $cutoff,        // last day of reservations
        public readonly Carbon $consolidateAt, // the vendor has its order by then
        public readonly Carbon $proformaAt,    // vendor's pro-invoice in, ours goes out
        public readonly Carbon $paymentDue,    // last day to pay
        public readonly Carbon $vendorPayAt,   // vendors are paid by then
        public readonly Carbon $deliveryFrom,
        public readonly Carbon $deliveryTo,
    ) {
    }

    /** The schedule of the calendar month $day (local) is in. */
    public static function forMonthOf(Carbon $day): self
    {
        $cfg = config('shop.monthly');
        $first = $day->copy()->startOfMonth();
        $on = function (int $d, string $time = '00:00') use ($first) {
            [$h, $m] = array_map('intval', explode(':', $time));

            return $first->copy()->setDay($d)->setTime($h, $m);
        };

        $cutoff = WorkingDays::onOrAfter($on($cfg['cutoff_day']));
        $consolidate = WorkingDays::following($on($cfg['consolidate_day'], $cfg['consolidate_time']), $cutoff);
        $proforma = WorkingDays::following($on($cfg['proforma_day'], $cfg['proforma_time']), $consolidate);
        $payment = WorkingDays::following($on($cfg['payment_day']), $proforma);
        $vendorPay = WorkingDays::following($on($cfg['vendor_payment_day'], $cfg['vendor_payment_time']), $payment);
        $deliveryFrom = $vendorPay->copy()->startOfDay();

        return new self(
            $cutoff,
            $consolidate,
            $proforma,
            $payment,
            $vendorPay,
            $deliveryFrom,
            WorkingDays::add($deliveryFrom, max(1, (int) $cfg['delivery_working_days']) - 1),
        );
    }

    /** The schedule of the period that closes on $periodTo (Y-m-d). */
    public static function forPeriodEnd(string $periodTo): self
    {
        return self::forMonthOf(Carbon::parse($periodTo, config('shop.weekly.timezone')));
    }
}
