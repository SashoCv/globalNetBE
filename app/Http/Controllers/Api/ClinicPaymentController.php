<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopPayment;
use App\Services\CPay\CPayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Starts a card payment for the logged-in clinic. The response carries the cPay
 * URL plus the hidden fields the browser must POST there — the redirect itself
 * happens in the browser, so cPay sees a Referer from our own domain.
 */
class ClinicPaymentController extends Controller
{
    public function __construct(private readonly CPayPaymentService $payments)
    {
    }

    // POST /api/clinic/payments/order/{id}
    public function startForOrder(Request $request, int $id): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $order = ShopOrder::where('shop_clinic_id', $clinic->id)->findOrFail($id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Нарачката е веќе платена.'], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Нарачката е откажана.'], 422);
        }

        return $this->build($order, $clinic);
    }

    // POST /api/clinic/payments/invoice/{id}
    public function startForInvoice(Request $request, int $id): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $invoice = ShopInvoice::where('shop_clinic_id', $clinic->id)->findOrFail($id);

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Фактурата е веќе платена.'], 422);
        }

        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'Фактурата е откажана.'], 422);
        }

        return $this->build($invoice, $clinic);
    }

    // GET /api/clinic/payments/{reference}
    // Used by the result page to show the outcome after coming back from cPay.
    public function show(Request $request, string $reference): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $payment = ShopPayment::where('shop_clinic_id', $clinic->id)
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json([
            'reference' => $payment->reference,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'cpay_payment_ref' => $payment->cpay_payment_ref,
            'payable_type' => class_basename($payment->payable_type),
            'payable_id' => $payment->payable_id,
            'paid_at' => $payment->paid_at,
        ]);
    }

    private function build(ShopOrder|ShopInvoice $payable, ShopClinic $clinic): JsonResponse
    {
        if (! config('cpay.merchant_id') || ! config('cpay.merchant_name')) {
            return response()->json([
                'message' => 'Плаќањето со картичка сè уште не е конфигурирано.',
            ], 503);
        }

        try {
            $payment = $this->payments->start($payable, $clinic);
            $form = $this->payments->redirectForm($payment);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'action' => $form['action'],
            'fields' => $form['fields'],
        ]);
    }
}
