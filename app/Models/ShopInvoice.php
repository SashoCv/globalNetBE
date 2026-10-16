<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number', 'shop_clinic_id',
        'period_from', 'period_to',
        'subtotal', 'surcharge_amount', 'total', 'currency',
        'status', 'issued_at', 'due_at', 'paid_at', 'payment_method',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'subtotal' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ShopClinic::class, 'shop_clinic_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class, 'shop_invoice_id');
    }

    /**
     * The monthly billing period a day falls in. Reservations close on the
     * 19th and the invoice goes out on the 20th, so an order placed from the
     * 20th on belongs to next month's invoice: periods run 20th → 19th.
     *
     * @return array{0: string, 1: string}  [from, to] as Y-m-d
     */
    public static function billingPeriod(Carbon $day): array
    {
        $day = $day->copy()->setTimezone(config('shop.weekly.timezone'))->startOfDay();
        $closes = $day->day <= 19 ? $day->copy()->setDay(19) : $day->copy()->addMonthNoOverflow()->setDay(19);

        return [
            $closes->copy()->subMonthNoOverflow()->setDay(20)->toDateString(),
            $closes->toDateString(),
        ];
    }

    /**
     * The most recent billing period that has already closed — what the
     * invoice run on the 20th bills.
     *
     * @return array{0: string, 1: string}
     */
    public static function lastClosedBillingPeriod(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->setTimezone(config('shop.weekly.timezone'));
        $lastCutoff = $now->day >= 20 ? $now->copy()->setDay(19) : $now->copy()->subMonthNoOverflow()->setDay(19);

        return self::billingPeriod($lastCutoff);
    }

    /**
     * A period's days are local (Skopje) days, while placed_at is stored in
     * UTC: an order placed at 00:30 on the 20th is the 19th in UTC and would
     * land on the wrong invoice by its UTC date.
     *
     * @return array{0: Carbon, 1: Carbon}  first and last instant, in UTC
     */
    public static function placedBetween(string $periodFrom, string $periodTo): array
    {
        $tz = config('shop.weekly.timezone');

        return [
            Carbon::parse($periodFrom, $tz)->startOfDay()->utc(),
            Carbon::parse($periodTo, $tz)->endOfDay()->utc(),
        ];
    }

    /**
     * Pull every un-invoiced order placed within [periodFrom, periodTo], group by
     * clinic, and create one invoice per clinic aggregating those orders.
     *
     * @return array{invoices: \Illuminate\Support\Collection, orders_count: int}
     */
    public static function generateForPeriod(
        string $periodFrom,
        string $periodTo,
        ?int $clinicId = null,
        ?string $dueAt = null
    ): array {
        $ordersQuery = ShopOrder::whereNull('shop_invoice_id')
            // Weekly group orders are paid up front on their own invoice
            // (urgent ones too — they already carry a shop_invoice_id).
            ->whereNull('shop_order_cycle_id')
            ->whereNotIn('status', ['cancelled'])
            // Pure service requests have no price and are never billed —
            // only orders with at least one real (priced) product qualify.
            ->whereHas('items', fn ($q) => $q->where('kind', 'product'))
            ->whereBetween('placed_at', self::placedBetween($periodFrom, $periodTo));

        if ($clinicId) {
            $ordersQuery->where('shop_clinic_id', $clinicId);
        }

        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            return ['invoices' => collect(), 'orders_count' => 0];
        }

        $dueAt = $dueAt ?? now()->addDays(5)->toDateString();

        $created = DB::transaction(function () use ($orders, $periodFrom, $periodTo, $dueAt) {
            $invoices = collect();

            foreach ($orders->groupBy('shop_clinic_id') as $clinicGroupId => $clinicOrders) {
                $invoice = self::create([
                    'invoice_number'   => self::nextNumber(),
                    'shop_clinic_id'   => $clinicGroupId,
                    'period_from'      => $periodFrom,
                    'period_to'        => $periodTo,
                    'subtotal'         => round((float) $clinicOrders->sum('subtotal'), 2),
                    'surcharge_amount' => round((float) $clinicOrders->sum('surcharge_amount'), 2),
                    'total'            => round((float) $clinicOrders->sum('total'), 2),
                    'currency'         => 'MKD',
                    'status'           => 'pending',
                    'issued_at'        => now(),
                    'due_at'           => $dueAt,
                ]);

                ShopOrder::whereIn('id', $clinicOrders->pluck('id'))
                    ->update(['shop_invoice_id' => $invoice->id]);

                $invoices->push($invoice);
            }

            return $invoices;
        });

        return ['invoices' => $created, 'orders_count' => $orders->count()];
    }

    /**
     * ЕШ-001-10/26: running number within the year, then month/year of issue.
     */
    public static function nextNumber(): string
    {
        $prefix = config('shop.invoice.number_prefix');
        $now = now()->setTimezone(config('shop.weekly.timezone'));
        $yy = $now->format('y');

        $last = self::where('invoice_number', 'like', "{$prefix}-%/{$yy}")
            ->pluck('invoice_number')
            ->map(fn ($n) => (int) explode('-', $n)[1])
            ->max();

        return sprintf('%s-%03d-%s/%s', $prefix, ($last ?? 0) + 1, $now->format('m'), $yy);
    }

    // The number has a slash in it; downloads get an ASCII-safe name.
    public function pdfFileName(): string
    {
        return 'faktura-' . str_replace('/', '-', Str::ascii($this->invoice_number)) . '.pdf';
    }
}
