<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Services\Shop\WeeklyCycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Weekly group-order cycles (Неделни збирни нарачки): the admin screen where
 * the group discount is approved, and the public deadlines the e-shop shows.
 */
class ShopOrderCycleController extends Controller
{
    public function __construct(private readonly WeeklyCycleService $weekly)
    {
    }

    // GET /api/public/shop-order-cycles/current
    // Deadlines for an order placed right now. Computed, so it answers even
    // before the week's first order has created the cycle.
    public function current(): JsonResponse
    {
        $window = $this->weekly->windowFor(now());

        return response()->json($window + [
            'now' => now(),
            'max_extra_discount_percent' => (float) config('shop.weekly.max_extra_discount_percent'),
            'delivery_working_days' => (int) config('shop.weekly.delivery_working_days'),
            'labels' => [
                'cutoff' => $this->weekly->formatLocal($window['cutoff_at']),
                'discount_due' => $this->weekly->formatLocal($window['discount_due_at']),
                'payment_due' => $this->weekly->formatLocal($window['payment_due_at']),
                'delivery_from' => $this->weekly->formatDate($window['delivery_from']),
                'delivery_to' => $this->weekly->formatDate($window['delivery_to']),
            ],
        ]);
    }

    // GET /api/shop-order-cycles
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 5), 100);

        $cycles = ShopOrderCycle::query()
            ->orderByDesc('cutoff_at')
            ->paginate($perPage);

        $cycles->getCollection()->transform(fn (ShopOrderCycle $c) => $this->present($c));

        return response()->json($cycles);
    }

    // GET /api/shop-order-cycles/{id}
    public function show(int $id): JsonResponse
    {
        $cycle = ShopOrderCycle::findOrFail($id);

        $orders = ShopOrder::with(['clinic:id,name,city,email,phone'])
            ->withCount('items')
            ->where('shop_order_cycle_id', $cycle->id)
            ->orderByDesc('placed_at')
            ->get();

        return response()->json($this->present($cycle) + ['orders' => $orders]);
    }

    // POST /api/shop-order-cycles/{id}/approve-discount
    public function approveDiscount(Request $request, int $id): JsonResponse
    {
        $max = (float) config('shop.weekly.max_extra_discount_percent');
        $validated = $request->validate([
            'percent' => "required|numeric|min:0|max:{$max}",
        ]);

        $cycle = ShopOrderCycle::findOrFail($id);

        if ($cycle->status !== ShopOrderCycle::STATUS_CLOSED) {
            return response()->json([
                'message' => $cycle->status === ShopOrderCycle::STATUS_OPEN
                    ? 'Циклусот е сè уште отворен за нарачки. Попустот се внесува по ' . $this->weekly->formatLocal($cycle->cutoff_at) . '.'
                    : 'Попустот за овој циклус е веќе одобрен.',
            ], 422);
        }

        try {
            $cycle = $this->weekly->approveDiscount($cycle, (float) $validated['percent'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($cycle) + [
            'message' => 'Попустот е одобрен. Ординациите добија е-пошта со износот за плаќање.',
        ]);
    }

    // POST /api/shop-order-cycles/{id}/close-payment
    // By hand, for a cycle the scheduler could not close in time. Never before
    // the payment deadline — clinics were promised until then.
    public function closePayment(int $id): JsonResponse
    {
        $cycle = ShopOrderCycle::findOrFail($id);

        if ($cycle->status !== ShopOrderCycle::STATUS_PAYMENT_OPEN) {
            return response()->json(['message' => 'Плаќањето за овој циклус не е отворено.'], 422);
        }
        if (now()->lessThan($cycle->payment_due_at)) {
            return response()->json([
                'message' => 'Рокот за плаќање е ' . $this->weekly->formatLocal($cycle->payment_due_at) . '. Плаќањето не може да се затвори пред тоа.',
            ], 422);
        }

        $result = $this->weekly->closePayment($cycle);

        return response()->json($this->present($cycle->fresh()) + [
            'message' => "{$result['confirmed']} потврдени, {$result['cancelled']} откажани"
                . ($result['waiting'] ? ", {$result['waiting']} чекаат одговор од cPay — обидете се повторно за 30 минути." : '.'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShopOrderCycle $cycle): array
    {
        return $cycle->toArray() + [
            'stats' => $this->weekly->stats($cycle),
            'labels' => [
                'cutoff' => $this->weekly->formatLocal($cycle->cutoff_at),
                'discount_due' => $this->weekly->formatLocal($cycle->discount_due_at),
                'reminder' => $this->weekly->formatLocal($cycle->reminder_at),
                'payment_due' => $this->weekly->formatLocal($cycle->payment_due_at),
                'delivery_from' => $this->weekly->formatDate($cycle->delivery_from),
                'delivery_to' => $this->weekly->formatDate($cycle->delivery_to),
            ],
            'max_extra_discount_percent' => (float) config('shop.weekly.max_extra_discount_percent'),
            'needs_manual_close' => $cycle->status === ShopOrderCycle::STATUS_PAYMENT_OPEN
                && now()->greaterThanOrEqualTo($cycle->payment_due_at),
        ];
    }
}
