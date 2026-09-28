<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin view of the card payments made through the cPay virtual POS.
 * Read-only: a payment is created and settled by the customer and by cPay,
 * never from here.
 */
class ShopPaymentController extends Controller
{
    // GET /api/shop-payments
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|max:20',
            'search' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ShopPayment::query()
            ->with(['clinic:id,name,city', 'payable'])
            ->latest('id');

        if (! empty($validated['status']) && $validated['status'] !== 'all') {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);

            $query->where(function ($q) use ($term) {
                $q->where('reference', $term)
                    ->orWhere('cpay_payment_ref', 'like', "%{$term}%")
                    ->orWhere('details1', 'like', "%{$term}%")
                    ->orWhereHas('clinic', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        $page = $query->paginate($validated['per_page'] ?? 25);

        $page->getCollection()->transform(fn (ShopPayment $payment) => $this->present($payment));

        return response()->json($page);
    }

    // GET /api/shop-payments/stats
    public function stats(): JsonResponse
    {
        $paid = ShopPayment::where('status', ShopPayment::STATUS_PAID);

        return response()->json([
            'paid_count' => (clone $paid)->count(),
            'paid_total' => round((float) (clone $paid)->sum('amount'), 2),
            'paid_today' => (clone $paid)->whereDate('paid_at', today())->count(),
            'paid_today_total' => round((float) (clone $paid)->whereDate('paid_at', today())->sum('amount'), 2),
            'failed_count' => ShopPayment::whereIn('status', [
                ShopPayment::STATUS_FAILED,
                ShopPayment::STATUS_CANCELLED,
            ])->count(),
            // Started but never finished: the customer left cPay, or a
            // notification never arrived.
            'pending_count' => ShopPayment::whereIn('status', [
                ShopPayment::STATUS_NEW,
                ShopPayment::STATUS_REDIRECTED,
            ])->count(),
        ]);
    }

    // GET /api/shop-payments/{id}
    public function show(int $id): JsonResponse
    {
        $payment = ShopPayment::with(['clinic:id,name,city,email,phone', 'payable'])->findOrFail($id);

        return response()->json($this->present($payment, withPayloads: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShopPayment $payment, bool $withPayloads = false): array
    {
        $payable = $payment->payable;
        $isInvoice = class_basename($payment->payable_type) === 'ShopInvoice';

        $data = [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'details1' => $payment->details1,
            'cpay_payment_ref' => $payment->cpay_payment_ref,
            'failure_reason' => $payment->failure_reason,
            'created_at' => $payment->created_at,
            'redirected_at' => $payment->redirected_at,
            'paid_at' => $payment->paid_at,
            'failed_at' => $payment->failed_at,
            'clinic' => $payment->clinic ? [
                'id' => $payment->clinic->id,
                'name' => $payment->clinic->name,
                'city' => $payment->clinic->city,
            ] : null,
            'target' => [
                'kind' => $isInvoice ? 'invoice' : 'order',
                'id' => $payment->payable_id,
                'number' => $isInvoice
                    ? ($payable->invoice_number ?? null)
                    : ($payable->order_number ?? null),
                'total' => $payable->total ?? null,
                'status' => $isInvoice ? ($payable->status ?? null) : ($payable->payment_status ?? null),
            ],
        ];

        if ($withPayloads) {
            // What we sent and what cPay sent back — the only record of a
            // disputed transaction that is not in cPay's own merchant module.
            $data['request_payload'] = $payment->request_payload;
            $data['response_payload'] = $payment->response_payload;
        }

        return $data;
    }
}
