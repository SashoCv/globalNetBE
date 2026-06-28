<?php

namespace App\Console\Commands;

use App\Models\ShopOrderItem;
use App\Models\ShopProcurementDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class WeeklyProcurementDispatch extends Command
{
    protected $signature = 'shop:weekly-dispatch
                            {--from= : Period start date (default: 1st of current month)}
                            {--to=   : Period end date (default: today)}
                            {--dry-run : Show what would be dispatched without saving}';

    protected $description = 'Generate weekly procurement dispatch files (CSV/XLS) per vendor for reserved/confirmed orders.';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->startOfMonth()->toDateString();
        $to   = $this->option('to')   ?: now()->toDateString();
        $dry  = (bool) $this->option('dry-run');

        $this->info("Weekly dispatch — period: {$from} → {$to}" . ($dry ? ' [DRY RUN]' : ''));

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
                    'id'       => $vId,
                    'name'     => $item->vendor?->name ?? 'Без добавувач',
                    'email'    => $item->vendor?->email,
                    'products' => [],
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
                    'emailed'         => false,
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
