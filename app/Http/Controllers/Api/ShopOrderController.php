<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CourierDeliveryMail;
use App\Mail\VendorProcurementOrderMail;
use App\Models\Setting;
use App\Models\ShopClinic;
use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopProcurementDispatch;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ShopOrderController extends Controller
{
    private const STATUSES = ['new', 'reserved', 'confirmed', 'processing', 'in_delivery', 'completed', 'cancelled'];

    // GET /api/shop-orders (paginated, filtered)
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 5), 200);

        $query = ShopOrder::query()
            ->with([
                'clinic:id,name,city,email,phone',
                'orderModel:id,code,label,title,accent_color',
                'items:id,shop_order_id,product_name,quantity,unit_sale_price,subtotal',
            ])
            ->withCount('items')
            ->orderByDesc('placed_at')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            if ($status !== 'all' && in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($modelCode = $request->query('model')) {
            $query->whereHas('orderModel', fn ($q) => $q->where('code', $modelCode));
        }
        if ($kind = $request->query('kind')) {
            if ($kind === 'service') {
                // Pure service requests — no priced product lines.
                $query->whereHas('items', fn ($q) => $q->where('kind', 'service'))
                      ->whereDoesntHave('items', fn ($q) => $q->where('kind', '!=', 'service'));
            } elseif ($kind === 'product') {
                $query->whereHas('items', fn ($q) => $q->where('kind', 'product'));
            }
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('order_number', 'like', $like)
                    ->orWhereHas('clinic', fn ($qq) => $qq->where('name', 'like', $like)
                                                          ->orWhere('email', 'like', $like));
            });
        }
        if ($from = $request->query('from')) {
            $query->whereDate('placed_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('placed_at', '<=', $to);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Resolve a status-scope keyword (or a single status) to a list of statuses.
     */
    private function resolveStatuses(string $scope): array
    {
        return match ($scope) {
            'all' => self::STATUSES,
            'all_except_cancelled' => ['new', 'confirmed', 'processing', 'in_delivery', 'completed'],
            'new_confirmed' => ['new', 'confirmed'],
            'active' => ['new', 'confirmed', 'processing', 'in_delivery'],
            default => in_array($scope, self::STATUSES, true)
                ? [$scope]
                : ['new', 'confirmed', 'processing', 'in_delivery'],
        };
    }

    // GET /api/shop-orders/procurement
    // Aggregates order items grouped by vendor → product, with total quantities
    // to procure and a per-clinic breakdown (who ordered what).
    public function procurement(Request $request): JsonResponse
    {
        $scope = (string) $request->query('status', 'active');
        $statuses = $this->resolveStatuses($scope);
        $from = $request->query('from');
        $to = $request->query('to');

        $result = $this->aggregateProcurement($statuses, $from, $to, $request->query('vendor'));

        // Attach the latest dispatch (sent status) per vendor.
        $vendorIds = array_values(array_filter(array_map(fn ($v) => $v['vendor']['id'] ?? null, $result)));
        $lastByVendor = ShopProcurementDispatch::whereIn('shop_vendor_id', $vendorIds)
            ->orderByDesc('sent_at')
            ->get()
            ->groupBy('shop_vendor_id');

        foreach ($result as &$v) {
            $vid = $v['vendor']['id'] ?? null;
            $d = $vid ? ($lastByVendor[$vid][0] ?? null) : null;
            $v['last_dispatch'] = $d ? [
                'id' => $d->id,
                'status' => $d->status,
                'emailed' => $d->emailed,
                'to_email' => $d->to_email,
                'total_quantity' => $d->total_quantity,
                'sent_at' => $d->sent_at,
            ] : null;
        }
        unset($v);

        return response()->json([
            'vendors' => $result,
            'summary' => [
                'vendor_count' => count($result),
                'order_count' => ShopOrder::whereIn('status', $statuses)
                    ->when($from, fn ($q) => $q->whereDate('placed_at', '>=', $from))
                    ->when($to, fn ($q) => $q->whereDate('placed_at', '<=', $to))
                    ->count(),
                'total_quantity' => array_sum(array_column($result, 'total_quantity')),
                'total_cost' => round(array_sum(array_column($result, 'total_cost')), 2),
                'statuses' => $statuses,
            ],
        ]);
    }

    /**
     * Build the vendor → product aggregation used by both the procurement
     * view and the dispatch action. Returns a list of vendor groups.
     */
    private function aggregateProcurement(array $statuses, $from, $to, $vendorId = null): array
    {
        $items = ShopOrderItem::query()
            ->with([
                'vendor:id,name,slug,email,contact_person,phone,has_delivery',
                'order:id,order_number,shop_clinic_id,status,placed_at,requested_delivery_date',
                'order.clinic:id,name,city',
                'product:id,cost_price',
                'product.costTiers',
            ])
            ->whereHas('order', function ($q) use ($statuses, $from, $to) {
                $q->whereIn('status', $statuses);
                if ($from) $q->whereDate('placed_at', '>=', $from);
                if ($to) $q->whereDate('placed_at', '<=', $to);
            })
            ->when($vendorId, fn ($q, $v) => $q->where('shop_vendor_id', $v))
            ->get();

        $vendors = [];
        foreach ($items as $it) {
            $vId = $it->shop_vendor_id ?? 0;
            if (!isset($vendors[$vId])) {
                $vendors[$vId] = [
                    'vendor' => $it->vendor
                        ? [
                            'id' => $it->vendor->id,
                            'name' => $it->vendor->name,
                            'slug' => $it->vendor->slug,
                            'email' => $it->vendor->email,
                            'contact_person' => $it->vendor->contact_person,
                            'phone' => $it->vendor->phone,
                            'has_delivery' => (bool) $it->vendor->has_delivery,
                        ]
                        : ['id' => null, 'name' => 'Без добавувач', 'slug' => null, 'email' => null, 'contact_person' => null, 'phone' => null, 'has_delivery' => false],
                    'total_quantity' => 0,
                    'total_cost' => 0.0,
                    'order_ids' => [],
                    'products' => [],
                ];
            }

            $pKey = $it->shop_product_id ?? ('name:' . $it->product_name);
            if (!isset($vendors[$vId]['products'][$pKey])) {
                $vendors[$vId]['products'][$pKey] = [
                    'product_id' => $it->shop_product_id,
                    'product_name' => $it->product_name,
                    'product_sku' => $it->product_sku,
                    'kind' => $it->kind,
                    'unit_cost_price' => (float) $it->unit_cost_price,
                    'total_quantity' => 0,
                    'line_cost' => 0.0,
                    'buyers' => [],
                    'product_model' => $it->product, // live product (for tier rebate)
                ];
            }

            $qty = (int) $it->quantity;
            $cost = (float) $it->cost_subtotal;

            $vendors[$vId]['products'][$pKey]['total_quantity'] += $qty;
            $vendors[$vId]['products'][$pKey]['line_cost'] += $cost;
            $vendors[$vId]['products'][$pKey]['buyers'][] = [
                'clinic_id' => $it->order?->clinic?->id,
                'clinic_name' => $it->order?->clinic?->name ?? '—',
                'clinic_city' => $it->order?->clinic?->city,
                'quantity' => $qty,
                'order_id' => $it->order?->id,
                'order_number' => $it->order?->order_number,
                'status' => $it->order?->status,
                'placed_at' => $it->order?->placed_at,
                'requested_delivery_date' => $it->order?->requested_delivery_date,
            ];

            $vendors[$vId]['total_quantity'] += $qty;
            $vendors[$vId]['total_cost'] += $cost;
            $vendors[$vId]['order_ids'][$it->shop_order_id] = true;
        }

        $result = [];
        foreach ($vendors as $v) {
            $products = array_values($v['products']);
            foreach ($products as &$p) {
                $p['line_cost'] = round($p['line_cost'], 2);

                // Volume rebate: effective per-unit cost for the AGGREGATED qty.
                $model = $p['product_model'] ?? null;
                if ($model) {
                    $eff = $model->effectiveUnitCost((int) $p['total_quantity']);
                    $tier = $model->costTiers
                        ->filter(fn ($t) => $t->min_quantity <= $p['total_quantity'])
                        ->sortByDesc('min_quantity')
                        ->first();
                    $p['effective_unit_cost'] = round($eff, 2);
                    $p['rebated_line_cost'] = round($eff * $p['total_quantity'], 2);
                    $p['tier_min_quantity'] = $tier?->min_quantity;
                    $p['has_tiers'] = $model->costTiers->isNotEmpty();
                } else {
                    $p['effective_unit_cost'] = (float) $p['unit_cost_price'];
                    $p['rebated_line_cost'] = $p['line_cost'];
                    $p['tier_min_quantity'] = null;
                    $p['has_tiers'] = false;
                }
                unset($p['product_model']); // don't serialize the model

                // Most-recent buyers first within each product line.
                usort($p['buyers'], fn ($a, $b) => strcmp((string) $b['placed_at'], (string) $a['placed_at']));
            }
            unset($p);
            usort($products, fn ($a, $b) => $b['total_quantity'] <=> $a['total_quantity']);

            $result[] = [
                'vendor' => $v['vendor'],
                'total_quantity' => $v['total_quantity'],
                'total_cost' => round($v['total_cost'], 2),
                'order_count' => count($v['order_ids']),
                'product_count' => count($products),
                'products' => $products,
            ];
        }
        usort($result, fn ($a, $b) => $b['total_quantity'] <=> $a['total_quantity']);

        return $result;
    }

    // POST /api/shop-orders/procurement/dispatch
    // Records (and optionally e-mails) a procurement order for one vendor,
    // then marks it as sent. The server re-computes the aggregation itself.
    public function dispatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|integer|exists:shop_vendors,id',
            'status' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'email' => 'nullable|email',
            'note' => 'nullable|string|max:2000',
            'send_email' => 'boolean',
            'mark_in_delivery' => 'boolean',
        ]);

        $scope = $validated['status'] ?? 'active';
        $statuses = $this->resolveStatuses($scope);
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $sendEmail = (bool) ($validated['send_email'] ?? false);

        $groups = $this->aggregateProcurement($statuses, $from, $to, $validated['vendor_id']);
        $group = $groups[0] ?? null;

        if (!$group || empty($group['products'])) {
            return response()->json([
                'message' => 'Нема ставки за овој добавувач во избраниот опсег.',
            ], 422);
        }

        $vendor = ShopVendor::find($validated['vendor_id']);
        $toEmail = $validated['email'] ?? $vendor?->email;

        if ($sendEmail && !$toEmail) {
            return response()->json([
                'message' => 'Добавувачот нема е-пошта. Внесете е-пошта за да испратите.',
            ], 422);
        }

        // Snapshot of the product lines actually sent to the vendor.
        $itemsSnapshot = array_map(fn ($p) => [
            'product_name' => $p['product_name'],
            'product_sku' => $p['product_sku'],
            'total_quantity' => $p['total_quantity'],
            'unit_cost_price' => $p['unit_cost_price'],
            'line_cost' => $p['line_cost'],
        ], $group['products']);

        $emailed = false;
        if ($sendEmail && $toEmail) {
            try {
                Mail::to($toEmail)->send(new VendorProcurementOrderMail(
                    $vendor,
                    $group,
                    $validated['note'] ?? null,
                ));
                $emailed = true;
            } catch (\Throwable $e) {
                Log::error('[procurement dispatch] mail failed', [
                    'vendor_id' => $vendor?->id,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'message' => 'Е-поштата не се испрати: ' . $e->getMessage(),
                ], 502);
            }
        }

        $dispatch = ShopProcurementDispatch::create([
            'shop_vendor_id' => $vendor?->id,
            'vendor_name' => $group['vendor']['name'],
            'to_email' => $toEmail,
            'status' => 'sent',
            'scope' => $scope,
            'date_from' => $from,
            'date_to' => $to,
            'total_quantity' => $group['total_quantity'],
            'total_cost' => $group['total_cost'],
            'items' => $itemsSnapshot,
            'note' => $validated['note'] ?? null,
            'emailed' => $emailed,
            'sent_at' => now(),
        ]);

        // Sent to the vendor → the contributing orders are now being delivered.
        $markInDelivery = (bool) ($validated['mark_in_delivery'] ?? true);
        $ordersMarked = 0;
        if ($markInDelivery) {
            $orderIds = [];
            foreach ($group['products'] as $p) {
                foreach ($p['buyers'] as $b) {
                    if (!empty($b['order_id'])) $orderIds[$b['order_id']] = true;
                }
            }
            if ($orderIds) {
                $ordersMarked = ShopOrder::whereIn('id', array_keys($orderIds))
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->update(['status' => 'in_delivery']);
            }
        }

        $base = $emailed
            ? 'Нарачката е испратена по е-пошта и означена како испратена.'
            : 'Нарачката е означена како испратена.';
        if ($ordersMarked > 0) {
            $base .= " {$ordersMarked} нарачки се префрлени во „Во испорака“.";
        }

        return response()->json([
            'message' => $base,
            'dispatch' => $dispatch,
            'orders_marked' => $ordersMarked,
        ], 201);
    }

    /**
     * POST /api/shop-orders/procurement/dispatch-courier
     * Emails the delivery courier (configured in shop settings) the products to
     * deliver to a clinic, grouped by vendor (pickup point). Vendors that have
     * their own delivery (has_delivery) are EXCLUDED — they deliver themselves.
     */
    public function dispatchCourier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => 'required|integer|exists:shop_clinics,id',
            'status' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'email' => 'nullable|email',
            'note' => 'nullable|string|max:2000',
            'send_email' => 'boolean',
        ]);

        $statuses = $this->resolveStatuses($validated['status'] ?? 'active');
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $sendEmail = (bool) ($validated['send_email'] ?? false);

        $items = ShopOrderItem::query()
            ->with(['vendor:id,name,city,has_delivery'])
            ->whereHas('order', function ($q) use ($statuses, $from, $to, $validated) {
                $q->whereIn('status', $statuses)
                    ->where('shop_clinic_id', $validated['clinic_id']);
                if ($from) $q->whereDate('placed_at', '>=', $from);
                if ($to) $q->whereDate('placed_at', '<=', $to);
            })
            ->get();

        // Group by vendor, skipping vendors with their own delivery.
        $groups = [];
        $excludedVendors = [];
        foreach ($items as $it) {
            if ($it->vendor && $it->vendor->has_delivery) {
                $excludedVendors[$it->vendor->id] = $it->vendor->name;
                continue;
            }
            $vId = $it->shop_vendor_id ?? 0;
            if (!isset($groups[$vId])) {
                $groups[$vId] = [
                    'vendor' => $it->vendor
                        ? ['id' => $it->vendor->id, 'name' => $it->vendor->name, 'city' => $it->vendor->city]
                        : ['id' => null, 'name' => 'Без добавувач', 'city' => null],
                    'products' => [],
                    'total_quantity' => 0,
                ];
            }
            $pKey = $it->shop_product_id ?? ('name:' . $it->product_name);
            if (!isset($groups[$vId]['products'][$pKey])) {
                $groups[$vId]['products'][$pKey] = [
                    'product_name' => $it->product_name,
                    'product_sku' => $it->product_sku,
                    'total_quantity' => 0,
                ];
            }
            $groups[$vId]['products'][$pKey]['total_quantity'] += (int) $it->quantity;
            $groups[$vId]['total_quantity'] += (int) $it->quantity;
        }

        $groups = array_map(function ($g) {
            $g['products'] = array_values($g['products']);
            return $g;
        }, array_values($groups));

        $totalQty = array_sum(array_column($groups, 'total_quantity'));

        if (empty($groups)) {
            return response()->json([
                'message' => 'Нема производи за достава преку курир за оваа ординација (сите добавувачи имаат своја достава или нема нарачки).',
            ], 422);
        }

        $courierEmail = $validated['email']
            ?? Setting::where('group', 'shop')->where('key', 'delivery_provider_email')->value('value');
        $courierName = Setting::where('group', 'shop')->where('key', 'delivery_provider_name')->value('value');

        if ($sendEmail && !$courierEmail) {
            return response()->json([
                'message' => 'Нема конфигуриран доставувач. Внесете е-пошта или поставете доставувач во Поставки.',
            ], 422);
        }

        $clinic = ShopClinic::find($validated['clinic_id']);
        $clinicData = [
            'name' => $clinic?->name,
            'city' => $clinic?->city,
            'address' => $clinic?->address,
            'phone' => $clinic?->phone,
            'contact_person' => $clinic?->contact_person,
        ];

        $emailed = false;
        if ($sendEmail && $courierEmail) {
            try {
                Mail::to($courierEmail)->send(new CourierDeliveryMail(
                    $clinicData,
                    $groups,
                    $courierName,
                    $validated['note'] ?? null,
                ));
                $emailed = true;
            } catch (\Throwable $e) {
                Log::error('[courier dispatch] mail failed', [
                    'clinic_id' => $validated['clinic_id'],
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'message' => 'Е-поштата не се испрати: ' . $e->getMessage(),
                ], 502);
            }
        }

        return response()->json([
            'message' => $emailed
                ? 'Налогот за достава е испратен до доставувачот.'
                : 'Налогот е подготвен.',
            'emailed' => $emailed,
            'to_email' => $courierEmail,
            'vendor_count' => count($groups),
            'excluded_vendor_count' => count($excludedVendors),
            'total_quantity' => $totalQty,
        ], 201);
    }

    // POST /api/shop-orders/{id}/dispatch-to-vendors
    // Sends a single order to each involved vendor (their portion) and,
    // by default, moves the order to "in_delivery".
    public function dispatchOrder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
            'set_in_delivery' => 'boolean',
        ]);

        $order = ShopOrder::with([
            'items.vendor:id,name,email,contact_person',
            'clinic:id,name,city',
        ])->findOrFail($id);

        // Group this order's items by vendor.
        $byVendor = [];
        foreach ($order->items as $it) {
            $vid = $it->shop_vendor_id ?? 0;
            $byVendor[$vid] ??= ['vendor' => $it->vendor, 'items' => []];
            $byVendor[$vid]['items'][] = $it;
        }

        $results = [];
        foreach ($byVendor as $grp) {
            $vendor = $grp['vendor'];

            $products = [];
            $totalQty = 0;
            $totalCost = 0.0;
            foreach ($grp['items'] as $it) {
                $key = $it->shop_product_id ?? ('name:' . $it->product_name);
                $products[$key] ??= [
                    'product_name' => $it->product_name,
                    'product_sku' => $it->product_sku,
                    'total_quantity' => 0,
                    'unit_cost_price' => (float) $it->unit_cost_price,
                    'line_cost' => 0.0,
                ];
                $products[$key]['total_quantity'] += (int) $it->quantity;
                $products[$key]['line_cost'] += (float) $it->cost_subtotal;
                $totalQty += (int) $it->quantity;
                $totalCost += (float) $it->cost_subtotal;
            }
            $products = array_map(fn ($p) => [...$p, 'line_cost' => round($p['line_cost'], 2)], array_values($products));

            $group = [
                'vendor' => $vendor
                    ? ['id' => $vendor->id, 'name' => $vendor->name, 'email' => $vendor->email, 'contact_person' => $vendor->contact_person]
                    : ['id' => null, 'name' => 'Без добавувач', 'email' => null, 'contact_person' => null],
                'products' => $products,
                'total_quantity' => $totalQty,
                'total_cost' => round($totalCost, 2),
            ];

            $email = $vendor?->email;
            $note = $validated['note'] ?? ('Нарачка ' . $order->order_number);
            $emailed = false;
            if ($email) {
                try {
                    Mail::to($email)->send(new VendorProcurementOrderMail($vendor, $group, $note));
                    $emailed = true;
                } catch (\Throwable $e) {
                    Log::error('[order dispatch] mail failed', [
                        'order_id' => $order->id, 'vendor_id' => $vendor?->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            ShopProcurementDispatch::create([
                'shop_vendor_id' => $vendor?->id,
                'vendor_name' => $group['vendor']['name'],
                'to_email' => $email,
                'status' => 'sent',
                'scope' => 'order:' . $order->order_number,
                'total_quantity' => $totalQty,
                'total_cost' => $group['total_cost'],
                'items' => $products,
                'note' => $validated['note'] ?? null,
                'emailed' => $emailed,
                'sent_at' => now(),
            ]);

            $results[] = [
                'vendor_name' => $group['vendor']['name'],
                'to_email' => $email,
                'emailed' => $emailed,
            ];
        }

        $setDelivery = (bool) ($validated['set_in_delivery'] ?? true);
        $moved = false;
        if ($setDelivery && !in_array($order->status, ['completed', 'cancelled'], true)) {
            $order->update(['status' => 'in_delivery']);
            $moved = true;
        }

        $emailedCount = count(array_filter($results, fn ($r) => $r['emailed']));
        $msg = $emailedCount > 0
            ? "Нарачката е испратена до {$emailedCount} добавувач(и) по е-пошта."
            : 'Нарачката е означена како испратена (добавувачите немаат е-пошта).';
        if ($moved) $msg .= ' Статус: Во испорака.';

        return response()->json([
            'message' => $msg,
            'order' => $order->fresh(),
            'vendors' => $results,
        ]);
    }

    private const MK_MONTHS = [
        1 => 'Јан', 2 => 'Феб', 3 => 'Мар', 4 => 'Апр', 5 => 'Мај', 6 => 'Јун',
        7 => 'Јул', 8 => 'Авг', 9 => 'Сеп', 10 => 'Окт', 11 => 'Ное', 12 => 'Дек',
    ];

    // GET /api/shop-orders/dashboard?from=&to=
    // One-shot analytics: monthly trend, top products / vendors / clients, KPIs.
    public function dashboard(Request $request): JsonResponse
    {
        // Default: last 6 calendar months (incl. current).
        $to = $request->query('to')
            ? \Illuminate\Support\Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();
        $from = $request->query('from')
            ? \Illuminate\Support\Carbon::parse($request->query('from'))->startOfDay()
            : now()->copy()->subMonths(5)->startOfMonth();

        // All orders in range (for status breakdown + range count).
        $allOrders = ShopOrder::query()
            ->with('clinic:id,name,city')
            ->whereBetween('placed_at', [$from, $to])
            ->get(['id', 'shop_clinic_id', 'total', 'status', 'placed_at']);

        $sales = $allOrders->where('status', '!=', 'cancelled');

        // ── KPIs ──
        $totalOrders = $sales->count();
        $totalRevenue = (float) $sales->sum('total');
        $cancelledCount = $allOrders->where('status', 'cancelled')->count();
        $uniqueClinics = $sales->pluck('shop_clinic_id')->unique()->count();

        // ── Monthly buckets ──
        $buckets = [];
        $cursor = $from->copy()->startOfMonth();
        $endMonth = $to->copy()->startOfMonth();
        while ($cursor <= $endMonth) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = [
                'ym' => $key,
                'label' => self::MK_MONTHS[(int) $cursor->format('n')] . ' ' . $cursor->format('Y'),
                'orders' => 0,
                'revenue' => 0.0,
                'quantity' => 0,
            ];
            $cursor->addMonth();
        }
        foreach ($sales as $o) {
            $key = $o->placed_at?->format('Y-m');
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['orders']++;
                $buckets[$key]['revenue'] += (float) $o->total;
            }
        }

        // ── Items in range (non-cancelled) for product / vendor / client breakdowns ──
        $items = ShopOrderItem::query()
            ->with(['vendor:id,name', 'order:id,shop_clinic_id,placed_at,status', 'order.clinic:id,name,city'])
            ->whereHas('order', function ($q) use ($from, $to) {
                $q->whereBetween('placed_at', [$from, $to])->where('status', '!=', 'cancelled');
            })
            ->get();

        $totalQuantity = 0;
        $products = [];
        $vendors = [];
        $clients = [];
        foreach ($items as $it) {
            $qty = (int) $it->quantity;
            $rev = (float) $it->subtotal;
            $totalQuantity += $qty;

            $key = $it->order?->placed_at?->format('Y-m');
            if ($key && isset($buckets[$key])) $buckets[$key]['quantity'] += $qty;

            $pKey = $it->shop_product_id ?? ('name:' . $it->product_name);
            $products[$pKey] ??= ['product_id' => $it->shop_product_id, 'name' => $it->product_name, 'sku' => $it->product_sku, 'quantity' => 0, 'revenue' => 0.0];
            $products[$pKey]['quantity'] += $qty;
            $products[$pKey]['revenue'] += $rev;

            $vId = $it->shop_vendor_id ?? 0;
            $vendors[$vId] ??= ['vendor_id' => $it->vendor?->id, 'name' => $it->vendor?->name ?? 'Без добавувач', 'quantity' => 0, 'revenue' => 0.0, 'orders' => []];
            $vendors[$vId]['quantity'] += $qty;
            $vendors[$vId]['revenue'] += $rev;
            $vendors[$vId]['orders'][$it->shop_order_id] = true;

            $cId = $it->order?->shop_clinic_id ?? 0;
            $clients[$cId] ??= ['clinic_id' => $it->order?->clinic?->id, 'name' => $it->order?->clinic?->name ?? '—', 'city' => $it->order?->clinic?->city, 'quantity' => 0, 'revenue' => 0.0, 'orders' => []];
            $clients[$cId]['quantity'] += $qty;
            $clients[$cId]['revenue'] += $rev;
            $clients[$cId]['orders'][$it->shop_order_id] = true;
        }

        $topProducts = collect($products)
            ->sortByDesc('quantity')->take(10)
            ->map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'sku' => $p['sku'], 'quantity' => $p['quantity'], 'revenue' => round($p['revenue'], 2)])
            ->values();

        $topVendors = collect($vendors)
            ->map(fn ($v) => ['vendor_id' => $v['vendor_id'], 'name' => $v['name'], 'quantity' => $v['quantity'], 'revenue' => round($v['revenue'], 2), 'orders' => count($v['orders'])])
            ->sortByDesc('quantity')->take(8)->values();

        $topClients = collect($clients)
            ->map(fn ($c) => ['clinic_id' => $c['clinic_id'], 'name' => $c['name'], 'city' => $c['city'], 'quantity' => $c['quantity'], 'revenue' => round($c['revenue'], 2), 'orders' => count($c['orders'])])
            ->sortByDesc('revenue')->take(8)->values();

        $statusBreakdown = $allOrders->groupBy('status')->map->count();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total_orders' => $totalOrders,
                'total_revenue' => round($totalRevenue, 2),
                'total_quantity' => $totalQuantity,
                'avg_order_value' => $totalOrders ? round($totalRevenue / $totalOrders, 2) : 0,
                'unique_clinics' => $uniqueClinics,
                'cancelled_count' => $cancelledCount,
            ],
            'monthly' => array_values($buckets),
            'top_products' => $topProducts,
            'top_vendors' => $topVendors,
            'top_clients' => $topClients,
            'status_breakdown' => $statusBreakdown,
        ]);
    }

    // GET /api/shop-orders/stats
    // Counts respect the same model/date/search filters as the list (NOT the
    // status filter — we count per-status), so the chips match the table.
    public function stats(Request $request): JsonResponse
    {
        // Applies model / from / to / search filters to a fresh query.
        $scoped = function () use ($request) {
            $q = ShopOrder::query();
            if (($model = $request->query('model')) && $model !== 'all') {
                $q->whereHas('orderModel', fn ($qq) => $qq->where('code', $model));
            }
            if ($from = $request->query('from')) $q->whereDate('placed_at', '>=', $from);
            if ($to = $request->query('to')) $q->whereDate('placed_at', '<=', $to);
            if (($kind = $request->query('kind')) && $kind === 'service') {
                $q->whereHas('items', fn ($qq) => $qq->where('kind', 'service'))
                  ->whereDoesntHave('items', fn ($qq) => $qq->where('kind', '!=', 'service'));
            } elseif ($kind === 'product') {
                $q->whereHas('items', fn ($qq) => $qq->where('kind', 'product'));
            }
            if ($search = trim((string) $request->query('search', ''))) {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('order_number', 'like', $like)
                        ->orWhereHas('clinic', fn ($c) => $c->where('name', 'like', $like)
                                                            ->orWhere('email', 'like', $like));
                });
            }
            return $q;
        };

        $byStatus = $scoped()
            ->selectRaw('status, COUNT(*) as cnt, SUM(total) as revenue')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $byModel = $scoped()
            ->selectRaw('shop_order_model_id, COUNT(*) as cnt')
            ->groupBy('shop_order_model_id')
            ->pluck('cnt', 'shop_order_model_id');

        $totalRevenue = (float) $scoped()->where('status', 'completed')->sum('total');
        $totalProfit = (float) $scoped()->where('status', 'completed')->sum('subtotal')
                     - (float) $scoped()->where('status', 'completed')->sum('cost_subtotal');

        return response()->json([
            'total' => $byStatus->sum('cnt'),
            'by_status' => [
                'new' => (int) ($byStatus['new']->cnt ?? 0),
                'confirmed' => (int) ($byStatus['confirmed']->cnt ?? 0),
                'processing' => (int) ($byStatus['processing']->cnt ?? 0),
                'in_delivery' => (int) ($byStatus['in_delivery']->cnt ?? 0),
                'completed' => (int) ($byStatus['completed']->cnt ?? 0),
                'cancelled' => (int) ($byStatus['cancelled']->cnt ?? 0),
            ],
            'by_model' => $byModel,
            'total_revenue' => $totalRevenue,
            'total_profit' => $totalProfit,
        ]);
    }

    // GET /api/shop-orders/{id}
    public function show(int $id): JsonResponse
    {
        $order = ShopOrder::query()
            ->with([
                'clinic:id,name,email,phone,city,address,edb',
                'orderModel',
                'items',
                'items.vendor:id,name,slug,email,contact_person',
                'items.product:id,slug,image',
            ])
            ->findOrFail($id);

        return response()->json($order);
    }

    // PATCH /api/shop-orders/{id}/status
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', self::STATUSES),
            'admin_note' => 'nullable|string',
        ]);

        $order = ShopOrder::findOrFail($id);
        $patch = ['status' => $validated['status']];
        if (!empty($validated['admin_note'])) $patch['admin_note'] = $validated['admin_note'];
        if ($validated['status'] === 'cancelled') $patch['cancelled_at'] = now();
        if ($validated['status'] === 'completed') $patch['completed_at'] = now();
        $order->update($patch);

        return response()->json($order->fresh());
    }

    // PUT /api/shop-orders/{id}/items
    // Admin edits an order's line items (fix quantity / add missing item /
    // remove one) — typically in response to a clinic's ShopOrderRequest.
    // wallet_applied and loyalty_credit_earned are frozen; only the item
    // rows and the subtotal/surcharge/total derived from them change.
    public function updateItems(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.id'          => 'nullable|integer|exists:shop_order_items,id',
            'items.*.product_id'  => 'required_without:items.*.id|nullable|integer|exists:shop_products,id',
            'items.*.quantity'    => 'required|integer|min:1',
        ]);

        $order = ShopOrder::with(['items.product', 'orderModel'])->findOrFail($id);

        DB::transaction(function () use ($order, $validated) {
            $keepIds = collect($validated['items'])->pluck('id')->filter()->all();

            // Remove items no longer present — restore stock first.
            foreach ($order->items as $existing) {
                if (in_array($existing->id, $keepIds, true)) {
                    continue;
                }
                if ($existing->product && $existing->product->kind === 'product' && $existing->product->stock !== null) {
                    $existing->product->increment('stock', $existing->quantity);
                }
                $existing->delete();
            }

            $subtotal     = 0.0;
            $costSubtotal = 0.0;

            foreach ($validated['items'] as $row) {
                if (!empty($row['id'])) {
                    $item = ShopOrderItem::with('product')->findOrFail($row['id']);
                    $newQty  = (int) $row['quantity'];
                    $delta   = $newQty - $item->quantity;
                    if ($delta !== 0 && $item->product && $item->product->kind === 'product' && $item->product->stock !== null) {
                        $item->product->decrement('stock', $delta); // negative delta increments
                    }
                    $unitCost = $item->product ? $item->product->effectiveUnitCost($newQty) : (float) $item->unit_cost_price;
                    $item->update([
                        'quantity'      => $newQty,
                        'unit_cost_price' => $unitCost,
                        'subtotal'      => round((float) $item->unit_sale_price * $newQty, 2),
                        'cost_subtotal' => round($unitCost * $newQty, 2),
                    ]);
                } else {
                    $product = ShopProduct::with('vendor')->findOrFail($row['product_id']);
                    $qty      = (int) $row['quantity'];
                    $unitSale = (float) $product->price;
                    $unitCost = $product->effectiveUnitCost($qty);
                    $item = ShopOrderItem::create([
                        'shop_order_id'   => $order->id,
                        'shop_product_id' => $product->id,
                        'shop_vendor_id'  => $product->shop_vendor_id,
                        'product_name'    => $product->name,
                        'product_sku'     => $product->sku,
                        'kind'            => $product->kind,
                        'unit_sale_price' => $unitSale,
                        'unit_cost_price' => $unitCost,
                        'quantity'        => $qty,
                        'subtotal'        => round($unitSale * $qty, 2),
                        'cost_subtotal'   => round($unitCost * $qty, 2),
                    ]);
                    if ($product->kind === 'product' && $product->stock !== null) {
                        $product->decrement('stock', $qty);
                    }
                }

                $subtotal     += (float) $item->subtotal;
                $costSubtotal += (float) $item->cost_subtotal;
            }

            $surchargeAmount = (float) ($order->orderModel->surcharge_fixed_amount ?? 0);
            $total = max(0.0, round($subtotal + $surchargeAmount - (float) $order->wallet_applied, 2));

            $order->update([
                'subtotal'         => round($subtotal, 2),
                'cost_subtotal'    => round($costSubtotal, 2),
                'surcharge_amount' => $surchargeAmount,
                'total'            => $total,
            ]);
        });

        return response()->json(
            $order->fresh()->load(['items.product', 'items.vendor', 'orderModel', 'clinic'])
        );
    }

    // DELETE /api/shop-orders/{id}
    public function destroy(int $id): JsonResponse
    {
        ShopOrder::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // PATCH /api/shop-orders/{id}/mark-paid
    public function markPaid(int $id): JsonResponse
    {
        $order = ShopOrder::findOrFail($id);
        $order->update(['payment_status' => 'paid']);
        return response()->json($order->fresh());
    }

    // POST /api/shop-orders/calculate-rebates
    // Calculates group volume rebates for reserved/confirmed orders in a period,
    // credits each clinic's wallet proportionally.
    public function calculateRebates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $statuses = ['reserved', 'confirmed'];

        // Aggregate by vendor→product across all clinics in the period
        $items = ShopOrderItem::query()
            ->with([
                'product:id',
                'product.costTiers',
                'order:id,shop_clinic_id,shop_order_model_id,rebate_credited_at',
            ])
            ->whereHas('order', function ($q) use ($statuses, $validated) {
                $q->whereIn('status', $statuses)
                  ->whereNull('rebate_credited_at')
                  ->whereDate('placed_at', '>=', $validated['from'])
                  ->whereDate('placed_at', '<=', $validated['to']);
            })
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'Нема нарачки за пресметка во овој период.', 'clinics_credited' => 0, 'total_rebate' => 0]);
        }

        // Per-product: aggregate total_qty to find the effective tier cost
        $productTotals = [];
        $clinicItems   = []; // clinic_id → [ product_id → {qty, order_unit_cost} ]

        foreach ($items as $item) {
            $pid = $item->shop_product_id;
            if (!$pid) continue;

            $productTotals[$pid] = ($productTotals[$pid] ?? 0) + $item->quantity;

            $cid = $item->order?->shop_clinic_id;
            if (!$cid) continue;

            if (!isset($clinicItems[$cid][$pid])) {
                $clinicItems[$cid][$pid] = ['qty' => 0, 'paid_cost' => 0.0, 'product' => $item->product];
            }
            $clinicItems[$cid][$pid]['qty']       += $item->quantity;
            $clinicItems[$cid][$pid]['paid_cost']  += (float) $item->cost_subtotal;
        }

        // Calculate savings per clinic
        $clinicRebates = [];
        foreach ($clinicItems as $cid => $products) {
            $saving = 0.0;
            foreach ($products as $pid => $data) {
                $product = $data['product'];
                if (!$product) continue;
                $totalQty     = $productTotals[$pid] ?? $data['qty'];
                $effCost      = $product->effectiveUnitCost((int) $totalQty);
                $actualSaving = $data['paid_cost'] - ($effCost * $data['qty']);
                if ($actualSaving > 0) {
                    $saving += $actualSaving;
                }
            }
            if ($saving > 0.01) {
                $clinicRebates[$cid] = round($saving, 2);
            }
        }

        if (empty($clinicRebates)) {
            return response()->json(['message' => 'Нема рабат за кредитирање во овој период.', 'clinics_credited' => 0, 'total_rebate' => 0]);
        }

        // Credit each clinic wallet
        DB::transaction(function () use ($clinicRebates, $validated) {
            foreach ($clinicRebates as $cid => $rebateAmount) {
                $clinic = ShopClinic::find($cid);
                if (!$clinic) continue;

                $newBalance = (float) $clinic->wallet_balance + $rebateAmount;
                $clinic->update(['wallet_balance' => $newBalance]);

                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $cid,
                    'shop_order_id'  => null,
                    'type'           => 'credit',
                    'amount'         => $rebateAmount,
                    'balance_after'  => round($newBalance, 2),
                    'description'    => "Групен рабат за период {$validated['from']} – {$validated['to']}",
                ]);
            }

            // Mark orders as rebate credited so they can't be processed again
            $orderIds = ShopOrder::whereIn('status', ['reserved', 'confirmed'])
                ->whereNull('rebate_credited_at')
                ->whereDate('placed_at', '>=', $validated['from'])
                ->whereDate('placed_at', '<=', $validated['to'])
                ->pluck('id');

            ShopOrder::whereIn('id', $orderIds)->update([
                'rebate_credited_at' => now(),
                'rebate_amount'      => DB::raw(
                    'CASE shop_clinic_id ' .
                    implode(' ', array_map(fn ($cid, $amt) => "WHEN {$cid} THEN {$amt}", array_keys($clinicRebates), $clinicRebates)) .
                    ' ELSE 0 END'
                ),
            ]);
        });

        return response()->json([
            'message'          => 'Рабатите се успешно кредитирани.',
            'clinics_credited' => count($clinicRebates),
            'total_rebate'     => array_sum($clinicRebates),
            'breakdown'        => $clinicRebates,
        ]);
    }
}
