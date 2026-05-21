<?php

namespace Database\Seeders;

use App\Models\ShopClinic;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ShopOrderSeeder extends Seeder
{
    public function run(): void
    {
        $clinics = ShopClinic::where('status', 'approved')->get();
        if ($clinics->isEmpty()) {
            $this->command->warn('No approved clinics. Run ShopClinicSeeder first.');
            return;
        }

        $products = ShopProduct::where('status', 'active')->whereHas('vendor', fn($q) => $q->where('status', 'active'))->get();
        if ($products->isEmpty()) {
            $this->command->warn('No active products. Run ShopCatalogSeeder first.');
            return;
        }

        $models = ShopOrderModel::all()->keyBy('code');

        // Wipe old test orders so seeder is idempotent
        ShopOrder::query()->delete();

        $now = Carbon::now();
        $counter = 1;
        $created = 0;

        // Plan: ~15 orders across statuses + models.
        // Format: [model_code, status, days_ago, items_count]
        $plan = [
            // Urgent
            ['urgent', 'new',          0, 2],
            ['urgent', 'confirmed',    0, 1],
            ['urgent', 'processing',   1, 3],
            ['urgent', 'in_delivery',  1, 2],
            ['urgent', 'completed',    3, 2],
            // Monthly
            ['monthly', 'new',        0, 4],
            ['monthly', 'new',        1, 6],
            ['monthly', 'confirmed',  2, 3],
            ['monthly', 'processing', 4, 5],
            ['monthly', 'completed', 10, 4],
            ['monthly', 'completed', 25, 7],
            // Quarterly
            ['quarterly', 'new',         0, 5],
            ['quarterly', 'processing',  7, 6],
            ['quarterly', 'completed',  60, 8],
            // Cancelled
            ['urgent', 'cancelled',     5, 1],
        ];

        foreach ($plan as [$modelCode, $status, $daysAgo, $itemsCount]) {
            $model = $models[$modelCode] ?? null;
            $clinic = $clinics->random();
            $placedAt = $now->copy()->subDays($daysAgo)->subMinutes(random_int(0, 1440));

            $orderNumber = sprintf('ORD-%s-%04d', $placedAt->format('Y'), $counter++);

            $orderItems = [];
            $costSubtotal = 0;
            $saleSubtotal = 0;

            $selected = $products->random(min($itemsCount, $products->count()));
            foreach ($selected as $p) {
                $qty = $p->kind === 'service' ? 1 : random_int(1, 5);
                $unitSale = $p->price !== null ? (float) $p->price : random_int(100, 500);
                $unitCost = $p->cost_price !== null ? (float) $p->cost_price : ($unitSale * 0.7);
                $sub = round($unitSale * $qty, 2);
                $costSub = round($unitCost * $qty, 2);
                $orderItems[] = [
                    'shop_product_id' => $p->id,
                    'shop_vendor_id' => $p->shop_vendor_id,
                    'product_name' => $p->name,
                    'product_sku' => $p->sku,
                    'kind' => $p->kind,
                    'unit_cost_price' => $unitCost,
                    'unit_sale_price' => $unitSale,
                    'quantity' => $qty,
                    'subtotal' => $sub,
                    'cost_subtotal' => $costSub,
                ];
                $costSubtotal += $costSub;
                $saleSubtotal += $sub;
            }

            // Urgent surcharge from model config
            $surcharge = 0;
            if ($model && $model->code === 'urgent' && $model->surcharge_percent) {
                $surcharge = round($saleSubtotal * ((float) $model->surcharge_percent) / 100, 2);
            }
            $total = round($saleSubtotal + $surcharge, 2);

            // Requested delivery date — for monthly/quarterly use the model's delivery_day next cycle
            $requested = null;
            if ($model && $model->delivery_day) {
                $deliveryMonth = $placedAt->copy()->addMonth();
                $requested = Carbon::create(
                    $deliveryMonth->year, $deliveryMonth->month,
                    min($model->delivery_day, $deliveryMonth->daysInMonth)
                );
            } elseif ($model && $model->max_delivery_hours) {
                $requested = $placedAt->copy()->addHours($model->max_delivery_hours)->toDateString();
            }

            $order = ShopOrder::create([
                'order_number' => $orderNumber,
                'shop_clinic_id' => $clinic->id,
                'shop_order_model_id' => $model?->id,
                'status' => $status,
                'subtotal' => $saleSubtotal,
                'cost_subtotal' => $costSubtotal,
                'surcharge_amount' => $surcharge,
                'total' => $total,
                'currency' => 'MKD',
                'delivery_contact' => $clinic->contact_person,
                'delivery_phone' => $clinic->phone,
                'delivery_email' => $clinic->email,
                'delivery_city' => $clinic->city,
                'delivery_address' => $clinic->address,
                'delivery_notes' => null,
                'placed_at' => $placedAt,
                'requested_delivery_date' => $requested,
                'next_recurrence_date' => $model?->auto_repeat
                    ? $placedAt->copy()->addMonths($model->repeat_months)->toDateString()
                    : null,
                'cancelled_at' => $status === 'cancelled' ? $placedAt->copy()->addHours(2) : null,
                'completed_at' => $status === 'completed' ? $placedAt->copy()->addDays(2) : null,
                'admin_note' => $status === 'cancelled' ? 'Откажано од клиниката.' : null,
            ]);

            foreach ($orderItems as $item) {
                $item['shop_order_id'] = $order->id;
                ShopOrderItem::create($item);
            }
            $created++;
        }

        $this->command->info("$created test orders seeded across models and statuses.");
    }
}
