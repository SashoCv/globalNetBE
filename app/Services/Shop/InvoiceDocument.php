<?php

namespace App\Services\Shop;

use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Everything printed on an invoice. Shop prices include VAT, so the invoice
 * works backwards: each line is split into base and VAT at its own rate, and
 * the total always equals what the clinic pays.
 */
class InvoiceDocument
{
    private const ORDER_KINDS = [
        'urgent'    => 'ИТНА',
        'monthly'   => 'МЕСЕЧНА',
        'quarterly' => 'ТРОМЕСЕЧНА',
        'weekly'    => 'НЕДЕЛНА',
    ];

    public function pdf(ShopInvoice $invoice): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('pdf.shop-invoice', $this->data($invoice))->setPaper('a4');
    }

    public function data(ShopInvoice $invoice): array
    {
        $invoice->loadMissing('clinic', 'orders.items.product', 'orders.orderModel');

        $tz = config('shop.weekly.timezone');
        $orders = $invoice->orders->where('status', '!=', 'cancelled')->values();
        // A cancelled invoice still prints what was on it.
        if ($orders->isEmpty()) {
            $orders = $invoice->orders;
        }

        [$lines, $vat] = $this->lines($orders);

        $base = round(array_sum(array_column($vat, 'base')), 2);
        $tax = round(array_sum(array_column($vat, 'vat')), 2);

        return [
            'invoice'  => $invoice,
            'company'  => config('shop.invoice.company'),
            'logo'     => 'data:image/jpeg;base64,' . base64_encode(file_get_contents(resource_path('images/invoice-logo.jpg'))),
            'clinic'   => $invoice->clinic,
            'issuedOn' => Carbon::parse($invoice->issued_at ?? $invoice->created_at)->setTimezone($tz)->format('d.m.Y'),
            'dueOn'    => $invoice->due_at?->format('d.m.Y'),
            'orderNumbers' => $orders->pluck('order_number')->filter()->implode(', '),
            'orderKind' => $orders
                ->map(fn (ShopOrder $o) => self::ORDER_KINDS[$o->orderModel?->code] ?? null)
                ->filter()->unique()->implode(', '),
            'lines'    => $lines,
            'minRows'  => 15,
            'vat'      => $vat,
            'base'     => $base,
            'tax'      => $tax,
            'total'    => round((float) $invoice->total, 2),
            'inWords'  => self::amountInWords((float) $invoice->total),
            'complaintDays' => config('shop.invoice.complaint_days'),
        ];
    }

    /**
     * Invoice rows (amounts without VAT) and the VAT recap by rate.
     *
     * Group discount and points used lower an order's total below its items;
     * each order's lines are scaled down to its total before VAT is split, and
     * the difference is shown as its own row.
     *
     * @return array{0: array<int, array>, 1: array<int, array{rate: float, base: float, vat: float}>}
     */
    private function lines($orders): array
    {
        $surchargeRate = (float) config('shop.invoice.surcharge_vat_rate');
        $rows = [];
        $grossByRate = [];
        $reductions = ['discount' => 0.0, 'wallet' => 0.0, 'other' => 0.0];

        foreach ($orders as $order) {
            $orderGross = [];

            foreach ($order->items as $item) {
                $rate = $item->vatRate();
                $key = $item->product_name . '|' . $item->unit_sale_price . '|' . $rate;
                $rows[$key] ??= [
                    'name' => $item->product_name,
                    'unit' => (float) $item->unit_sale_price,
                    'qty' => 0,
                    'gross' => 0.0,
                    'rate' => $rate,
                ];
                $rows[$key]['qty'] += $item->quantity;
                $rows[$key]['gross'] += (float) $item->subtotal;
                $orderGross[(string) $rate] = ($orderGross[(string) $rate] ?? 0) + (float) $item->subtotal;
            }

            $surcharge = (float) $order->surcharge_amount;
            if ($surcharge > 0) {
                $key = 'surcharge|' . $surchargeRate;
                $rows[$key] ??= [
                    'name' => 'Надомест за итна нарачка',
                    'unit' => 0.0,
                    'qty' => 0,
                    'gross' => 0.0,
                    'rate' => $surchargeRate,
                ];
                $rows[$key]['qty']++;
                $rows[$key]['gross'] += $surcharge;
                $rows[$key]['unit'] = $rows[$key]['gross'] / $rows[$key]['qty'];
                $orderGross[(string) $surchargeRate] = ($orderGross[(string) $surchargeRate] ?? 0) + $surcharge;
            }

            $full = array_sum($orderGross);
            $total = (float) $order->total;
            if ($full <= 0) {
                continue;
            }

            foreach ($orderGross as $rate => $gross) {
                $grossByRate[$rate] = ($grossByRate[$rate] ?? 0) + $gross * $total / $full;
            }

            $discount = (float) $order->discount_amount;
            $wallet = (float) $order->wallet_applied;
            $reductions['discount'] += $discount;
            $reductions['wallet'] += $wallet;
            $reductions['other'] += $full - $total - $discount - $wallet;
        }

        // VAT recap, highest rate first, rounded so it adds up to the total.
        krsort($grossByRate, SORT_NUMERIC);
        $vat = [];
        foreach ($grossByRate as $rate => $gross) {
            $gross = round($gross, 2);
            $base = round($gross / (1 + $rate / 100), 2);
            $vat[] = ['rate' => (float) $rate, 'gross' => $gross, 'base' => $base, 'vat' => round($gross - $base, 2)];
        }

        $cent = round($orders->sum(fn ($o) => (float) $o->total) - array_sum(array_column($vat, 'gross')), 2);
        if ($vat && $cent != 0.0) {
            $vat[0]['gross'] = round($vat[0]['gross'] + $cent, 2);
            $vat[0]['vat'] = round($vat[0]['gross'] - $vat[0]['base'], 2);
        }

        $items = array_map(fn (array $r) => [
            'name' => $r['name'],
            'qty' => $r['qty'],
            'unit' => round($r['unit'] / (1 + $r['rate'] / 100), 2),
            'rate' => $r['rate'],
            'amount' => round($r['gross'] / (1 + $r['rate'] / 100), 2),
        ], array_values($rows));

        // Whatever separates the item rows from the VAT base is the reduction,
        // split between the reasons in proportion to their gross amounts.
        $gap = round(array_sum(array_column($vat, 'base')) - array_sum(array_column($items, 'amount')), 2);
        $labels = [
            'discount' => 'Збирен попуст',
            'wallet' => 'Искористени поени',
            'other' => 'Корекција',
        ];
        $reductions = array_filter($reductions, fn ($v) => abs($v) >= 0.01);
        $weight = array_sum($reductions);

        if ($reductions && abs($weight) >= 0.01) {
            $left = $gap;
            $last = array_key_last($reductions);
            foreach ($reductions as $reason => $gross) {
                $amount = $reason === $last ? $left : round($gap * $gross / $weight, 2);
                $left = round($left - $amount, 2);
                $items[] = ['name' => $labels[$reason], 'qty' => null, 'unit' => null, 'rate' => null, 'amount' => $amount];
            }
        } elseif ($items && abs($gap) >= 0.01) {
            // Rounding only — fold the cent into the last row.
            $items[array_key_last($items)]['amount'] = round($items[array_key_last($items)]['amount'] + $gap, 2);
        }

        return [$items, $vat];
    }

    /**
     * 33400.5 → "триесет и три илјади и четиристотини денари и 50/100".
     */
    public static function amountInWords(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $denars = (int) floor($amount);
        $cents = (int) round(($amount - $denars) * 100);

        $words = $denars === 0 ? 'нула' : self::numberInWords($denars);
        $words .= $denars === 1 ? ' денар' : ' денари';

        return $cents > 0 ? $words . ' и ' . sprintf('%02d', $cents) . '/100' : $words;
    }

    private static function numberInWords(int $n): string
    {
        $groups = [
            [1_000_000, 'милион', 'милиони', false],
            [1_000, 'илјада', 'илјади', true],
        ];

        $parts = [];
        foreach ($groups as [$size, $one, $many, $feminine]) {
            $count = intdiv($n, $size);
            $n %= $size;
            if ($count === 0) {
                continue;
            }
            if ($count === 1) {
                $parts[] = $feminine ? $one : 'еден ' . $one;
                continue;
            }
            $singular = $count % 10 === 1 && $count % 100 !== 11;
            $parts[] = self::hundreds($count, $feminine) . ' ' . ($singular ? $one : $many);
        }

        if ($n > 0) {
            $rest = self::hundreds($n, false);
            // "илјада и петстотини", but "илјада петстотини и дваесет".
            $parts[] = ($parts && ! str_contains($rest, ' и ') ? 'и ' : '') . $rest;
        }

        return implode(' ', $parts);
    }

    private static function hundreds(int $n, bool $feminine): string
    {
        $units = ['', 'еден', 'два', 'три', 'четири', 'пет', 'шест', 'седум', 'осум', 'девет'];
        if ($feminine) {
            $units[1] = 'една';
            $units[2] = 'две';
        }
        $teens = ['десет', 'единаесет', 'дванаесет', 'тринаесет', 'четиринаесет', 'петнаесет', 'шеснаесет', 'седумнаесет', 'осумнаесет', 'деветнаесет'];
        $tens = ['', '', 'дваесет', 'триесет', 'четириесет', 'педесет', 'шеесет', 'седумдесет', 'осумдесет', 'деведесет'];
        $hundreds = ['', 'сто', 'двесте', 'триста', 'четиристотини', 'петстотини', 'шестотини', 'седумстотини', 'осумстотини', 'деветстотини'];

        $h = intdiv($n, 100);
        $r = $n % 100;

        if ($r === 0) {
            $rest = '';
        } elseif ($r < 10) {
            $rest = $units[$r];
        } elseif ($r < 20) {
            $rest = $teens[$r - 10];
        } else {
            $rest = $tens[intdiv($r, 10)] . ($r % 10 ? ' и ' . $units[$r % 10] : '');
        }

        if ($h === 0) {
            return $rest;
        }
        if ($rest === '') {
            return $hundreds[$h];
        }

        return $hundreds[$h] . (str_contains($rest, ' и ') ? ' ' : ' и ') . $rest;
    }
}
