<?php

namespace App\Services\Shop;

use App\Mail\VendorPayablesAdminMail;
use App\Mail\VendorProcurementOrderMail;
use App\Models\AdminNotification;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopProcurementDispatch;
use App\Support\ShopAdmin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * What goes out to vendors: one order's items, the consolidated order of a week
 * or a month, and — to the admin — what each vendor is to be paid.
 */
class VendorDispatch
{
    /**
     * Send one order to each of its vendors, their own part only.
     *
     * @return array<int, array{vendor_name: string, to_email: ?string, emailed: bool}>
     */
    public function sendOrder(ShopOrder $order, ?string $note = null): array
    {
        $order->loadMissing('items.vendor');

        return $this->send(
            $this->groupByVendor($order->items),
            'order:' . $order->order_number,
            $note ?? 'Нарачка ' . $order->order_number,
            recordedNote: $note,
        );
    }

    /**
     * An urgent order goes to its vendors as soon as it is paid (Article 7,
     * Model 01). Sent once; a vendor that could not be mailed is reported.
     */
    public function sendPaidUrgentOrder(ShopOrder $order): void
    {
        if (ShopProcurementDispatch::where('scope', 'order:' . $order->order_number)->exists()) {
            return;
        }

        $results = $this->sendOrder($order, "Итна нарачка {$order->order_number} — платена. Испорака во рок од 24–72 часа (3 работни дена).");
        $this->reportNotMailed($results, "Итната нарачка {$order->order_number}", '/admin/shop-orders');
    }

    /**
     * One order per vendor with the total quantities of many orders. A vendor
     * that already got this scope is skipped, so it is safe to repeat. Order
     * statuses do not change: nothing is paid yet.
     *
     * @param  Collection<int, ShopOrder>  $orders
     */
    public function sendConsolidated(Collection $orders, string $scope, string $what, string $note, ?string $from = null, ?string $to = null): int
    {
        $items = ShopOrderItem::with('vendor')
            ->whereIn('shop_order_id', $orders->pluck('id'))
            ->where('kind', 'product')
            ->get();

        $sent = ShopProcurementDispatch::where('scope', $scope)->get()->map(fn ($d) => (int) $d->shop_vendor_id)->all();
        $groups = array_diff_key($this->groupByVendor($items), array_flip($sent));

        if (! $groups) {
            return 0;
        }

        $results = $this->send($groups, $scope, $note, $from, $to, $note, VendorProcurementOrderMail::RESERVATION);
        $this->reportNotMailed($results, $what, '/admin/shop-procurement');

        return count($results);
    }

    /**
     * What each vendor is owed for these (paid) orders.
     *
     * @param  Collection<int, ShopOrder>  $orders
     * @return array<int, array{vendor: string, orders: int, quantity: int, total: float}>
     */
    public function payables(Collection $orders): array
    {
        $items = ShopOrderItem::with('vendor')
            ->whereIn('shop_order_id', $orders->pluck('id'))
            ->where('kind', 'product')
            ->get();

        return $items->groupBy(fn ($i) => $i->shop_vendor_id ?? 0)
            ->map(fn ($rows) => [
                'vendor' => $rows->first()->vendor?->name ?? 'Без добавувач',
                'orders' => $rows->pluck('shop_order_id')->unique()->count(),
                'quantity' => (int) $rows->sum('quantity'),
                'total' => round((float) $rows->sum('cost_subtotal'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{vendor: string, orders: int, quantity: int, total: float}>  $payables
     */
    public function mailPayables(string $title, string $deadline, array $payables): void
    {
        $emails = ShopAdmin::emails();

        if (! $emails || ! $payables) {
            return;
        }

        try {
            Mail::to($emails)->send(new VendorPayablesAdminMail($title, $deadline, $payables));
        } catch (\Throwable $e) {
            Log::error('[vendor payables] mail failed', ['title' => $title, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  iterable<ShopOrderItem>  $items
     * @return array<int, array<string, mixed>>  keyed by vendor id (0 = none)
     */
    private function groupByVendor(iterable $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $vendorId = (int) ($item->shop_vendor_id ?? 0);
            $groups[$vendorId] ??= ['model' => $item->vendor, 'products' => []];

            $key = $item->shop_product_id ?? ('name:' . $item->product_name);
            $groups[$vendorId]['products'][$key] ??= [
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'total_quantity' => 0,
                'unit_cost_price' => (float) $item->unit_cost_price,
                'line_cost' => 0.0,
            ];
            $groups[$vendorId]['products'][$key]['total_quantity'] += (int) $item->quantity;
            $groups[$vendorId]['products'][$key]['line_cost'] += (float) $item->cost_subtotal;
        }

        return array_map(function (array $g) {
            $products = array_map(fn ($p) => [...$p, 'line_cost' => round($p['line_cost'], 2)], array_values($g['products']));
            $vendor = $g['model'];

            return [
                'model' => $vendor,
                'vendor' => $vendor
                    ? ['id' => $vendor->id, 'name' => $vendor->name, 'email' => $vendor->email, 'contact_person' => $vendor->contact_person]
                    : ['id' => null, 'name' => 'Без добавувач', 'email' => null, 'contact_person' => null],
                'products' => $products,
                'total_quantity' => array_sum(array_column($products, 'total_quantity')),
                'total_cost' => round(array_sum(array_column($products, 'line_cost')), 2),
            ];
        }, $groups);
    }

    /**
     * Mail every group to its vendor and record it. A failed mail is logged and
     * recorded as not e-mailed; it never stops the others.
     */
    private function send(array $groups, string $scope, string $note, ?string $from = null, ?string $to = null, ?string $recordedNote = null, string $kind = VendorProcurementOrderMail::ORDER): array
    {
        $results = [];

        foreach ($groups as $group) {
            $vendor = $group['model'];
            unset($group['model']);
            $email = $vendor?->email;
            $emailed = false;

            if ($email) {
                try {
                    Mail::to($email)->send(new VendorProcurementOrderMail($vendor, $group, $note, $kind));
                    $emailed = true;
                } catch (\Throwable $e) {
                    Log::error('[vendor dispatch] mail failed', [
                        'scope' => $scope, 'vendor_id' => $vendor->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            ShopProcurementDispatch::create([
                'shop_vendor_id' => $vendor?->id,
                'vendor_name' => $group['vendor']['name'],
                'to_email' => $email,
                'status' => 'sent',
                'scope' => $scope,
                'date_from' => $from,
                'date_to' => $to,
                'total_quantity' => $group['total_quantity'],
                'total_cost' => $group['total_cost'],
                'items' => $group['products'],
                'note' => $recordedNote,
                'emailed' => $emailed,
                'sent_at' => now(),
            ]);

            $results[] = ['vendor_name' => $group['vendor']['name'], 'to_email' => $email, 'emailed' => $emailed];
        }

        return $results;
    }

    private function reportNotMailed(array $results, string $what, string $link): void
    {
        $missed = array_column(array_filter($results, fn ($r) => ! $r['emailed']), 'vendor_name');

        if (! $missed) {
            return;
        }

        try {
            AdminNotification::notify(
                type: 'vendor_dispatch_failed',
                title: 'Нарачка не е испратена до добавувач',
                body: "{$what} не е испратена по е-пошта до: " . implode(', ', $missed) . '. Испратете ја рачно.',
                link: $link,
            );
        } catch (\Throwable $e) {
            Log::error('[vendor dispatch] notification failed', ['error' => $e->getMessage()]);
        }
    }
}
