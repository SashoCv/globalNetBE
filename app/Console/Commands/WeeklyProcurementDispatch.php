<?php

// SEKOJ PONEDELNIK OVAA KOMANDA VO 9H DO DOBAVUVACHI

namespace App\Console\Commands;

use App\Mail\VendorProcurementOrderMail;
use App\Models\ShopOrderItem;
use App\Models\ShopProcurementDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class WeeklyProcurementDispatch extends Command
{
    protected $signature = 'shop:weekly-dispatch
                            {--from= : Period start date (default: 1st of current month)}
                            {--to=   : Period end date (default: today)}
                            {--dry-run : Show what would be dispatched without saving}
                            {--no-email : Only generate files, do not email vendors}';

    protected $description = 'Generate weekly procurement dispatch files (CSV/XLS) per vendor for reserved/confirmed orders, and email each vendor a summary of what they need to supply.';

    public function handle(): int
    {
        $from  = $this->option('from') ?: now()->startOfMonth()->toDateString();
        $to    = $this->option('to')   ?: now()->toDateString();
        $dry   = (bool) $this->option('dry-run');
        $email = !$this->option('no-email');

        $this->info("Weekly dispatch — период: {$from} → {$to}" . ($dry ? ' [DRY RUN]' : ''));

        $items = ShopOrderItem::query()
            ->with([
                'vendor:id,name,email,contact_person',
                'order:id,shop_clinic_id,status,placed_at',
            ])
            ->whereHas('order', function ($q) use ($from, $to) {
                $q->whereIn('status', ['reserved', 'confirmed'])
                  ->whereDate('placed_at', '>=', $from)
                  ->whereDate('placed_at', '<=', $to);
            })
            ->get();

        if ($items->isEmpty()) {
            $this->warn('Нема нарачки за dispatch во овој период.');
            return 0;
        }

        // Group by vendor
        $vendors = [];
        foreach ($items as $item) {
            $vId = $item->shop_vendor_id ?? 0;
            if (!isset($vendors[$vId])) {
                $vendors[$vId] = [
                    'id'              => $vId,
                    'model'           => $item->vendor,
                    'name'            => $item->vendor?->name ?? 'Без добавувач',
                    'email'           => $item->vendor?->email,
                    'contact_person'  => $item->vendor?->contact_person,
                    'products'        => [],
                ];
            }
            $pKey = $item->shop_product_id ?? ('name:' . $item->product_name);
            if (!isset($vendors[$vId]['products'][$pKey])) {
                $vendors[$vId]['products'][$pKey] = [
                    'product_name' => $item->product_name,
                    'product_sku'  => $item->product_sku ?? '',
                    'kind'         => $item->kind,
                    'unit_cost'    => (float) $item->unit_cost_price,
                    'total_qty'    => 0,
                    'total_cost'   => 0.0,
                    'order_count'  => 0,
                ];
            }
            $vendors[$vId]['products'][$pKey]['total_qty']   += $item->quantity;
            $vendors[$vId]['products'][$pKey]['total_cost']  += (float) $item->cost_subtotal;
            $vendors[$vId]['products'][$pKey]['order_count']++;
        }

        $date    = now()->toDateString();
        $folder  = "dispatches/{$date}";
        $saved   = 0;

        foreach ($vendors as $vendor) {
            $products = array_values($vendor['products']);
            $totalQty  = array_sum(array_column($products, 'total_qty'));
            $totalCost = round(array_sum(array_column($products, 'total_cost')), 2);

            // Build CSV content (readable as XLS by Excel)
            $lines = [];
            $lines[] = implode("\t", ['Производ', 'SKU', 'Количина', 'Ед.цена', 'Вкупно', 'Нарачки (бр.)']);
            foreach ($products as $p) {
                $lines[] = implode("\t", [
                    $p['product_name'],
                    $p['product_sku'],
                    $p['total_qty'],
                    number_format($p['unit_cost'], 2, '.', ''),
                    number_format($p['total_cost'], 2, '.', ''),
                    $p['order_count'],
                ]);
            }
            $lines[] = '';
            $lines[] = implode("\t", ['ВКУПНО', '', $totalQty, '', number_format($totalCost, 2, '.', ''), '']);
            $lines[] = '';
            $lines[] = "Генерирано: {$date} | Период: {$from} – {$to} | GNA платформа";

            $content  = "\xEF\xBB\xBF" . implode("\r\n", $lines); // UTF-8 BOM for Excel
            $filename = "{$folder}/" . $this->slug($vendor['name']) . ".xls";

            $this->line("  → {$vendor['name']}: {$totalQty} ставки, {$totalCost} МКД — {$filename}");

            if (!$dry) {
                Storage::put($filename, $content);

                $emailed = false;
                if ($email && $vendor['email']) {
                    try {
                        $group = [
                            'vendor' => [
                                'id'             => $vendor['id'],
                                'name'           => $vendor['name'],
                                'email'          => $vendor['email'],
                                'contact_person' => $vendor['contact_person'],
                            ],
                            'products' => array_map(fn ($p) => [
                                'product_name'    => $p['product_name'],
                                'product_sku'     => $p['product_sku'],
                                'total_quantity'  => $p['total_qty'],
                                'unit_cost_price' => $p['unit_cost'],
                                'line_cost'       => $p['total_cost'],
                            ], $products),
                            'total_quantity' => $totalQty,
                            'total_cost'     => $totalCost,
                        ];

                        Mail::to($vendor['email'])->send(new VendorProcurementOrderMail(
                            $vendor['model'],
                            $group,
                            "Неделен преглед на резервирани нарачки за период {$from} – {$to}."
                        ));
                        $emailed = true;
                        $this->line("    ✓ e-пошта испратена до {$vendor['email']}");
                    } catch (\Throwable $e) {
                        Log::error('[weekly-dispatch] mail failed', [
                            'vendor_id' => $vendor['id'],
                            'error'     => $e->getMessage(),
                        ]);
                        $this->error("    ✗ e-поштата не успеа за {$vendor['name']}: {$e->getMessage()}");
                    }
                }

                // Record dispatch
                ShopProcurementDispatch::create([
                    'shop_vendor_id'  => $vendor['id'] ?: null,
                    'vendor_name'     => $vendor['name'],
                    'to_email'        => $vendor['email'],
                    'status'          => 'sent',
                    'scope'           => 'reserved',
                    'date_from'       => $from,
                    'date_to'         => $to,
                    'total_quantity'  => $totalQty,
                    'total_cost'      => $totalCost,
                    'items'           => $products,
                    'emailed'         => $emailed,
                    'sent_at'         => now(),
                ]);
                $saved++;
            }
        }

        $this->info($dry
            ? "DRY RUN: {$saved} dispatch записи (без зачувување)."
            : "Завршено: {$saved} dispatch записи зачувани во storage/{$folder}/"
        );

        return 0;
    }

    private function slug(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/[^\w\s-]/u', '', $name);
        $name = preg_replace('/[\s_-]+/', '-', trim($name));
        return $name ?: 'vendor';
    }
}
