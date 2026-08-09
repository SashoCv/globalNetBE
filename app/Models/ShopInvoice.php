<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
            ->whereNotIn('status', ['cancelled'])
            // Pure service requests have no price and are never billed —
            // only orders with at least one real (priced) product qualify.
            ->whereHas('items', fn ($q) => $q->where('kind', 'product'))
            ->whereDate('placed_at', '>=', $periodFrom)
            ->whereDate('placed_at', '<=', $periodTo);

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
                    'invoice_number'   => self::generateInvoiceNumber(),
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

    private static function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";
        $last = self::where('invoice_number', 'like', $prefix . '%')
            ->max(DB::raw("CAST(SUBSTR(invoice_number, " . (strlen($prefix) + 1) . ") AS INTEGER)"));
        $seq = ($last ?? 0) + 1;
        return sprintf('INV-%d-%05d', $year, $seq);
    }
}
