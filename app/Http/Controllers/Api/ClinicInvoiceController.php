<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Services\Shop\MonthlySchedule;
use App\Services\Shop\InvoiceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicInvoiceController extends Controller
{
    // GET /api/clinic/invoices
    public function index(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $invoices = ShopInvoice::withCount('orders')
            ->where('shop_clinic_id', $clinic->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($invoices);
    }

    // GET /api/clinic/invoices/draft
    // Live preview of the invoice currently being formed for this billing period
    // (nominally the 20th of last month to the 19th). Not a real ShopInvoice yet —
    // nothing is persisted, it just reflects the clinic's not-yet-invoiced orders.
    // It becomes a real, payable invoice on issues_at, the 21st at 12:00; until
    // then a period that has just closed is the one shown.
    public function draft(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $periods = [ShopInvoice::billingPeriod(now())];
        $closed = ShopInvoice::lastClosedBillingPeriod(now());
        if (now()->lessThan(MonthlySchedule::forPeriodEnd($closed[1])->proformaAt)) {
            array_unshift($periods, $closed);
        }

        foreach ($periods as [$periodFrom, $periodTo]) {
            $orders = ShopOrder::with('orderModel:id,code,label,title')
                ->where('shop_clinic_id', $clinic->id)
                ->whereNull('shop_invoice_id')
                ->whereNull('shop_order_cycle_id')
                ->whereNotIn('status', ['cancelled'])
                ->whereHas('items', fn ($q) => $q->where('kind', 'product'))
                ->whereBetween('placed_at', ShopInvoice::placedBetween($periodFrom, $periodTo))
                ->orderByDesc('placed_at')
                ->get();

            if ($orders->isNotEmpty()) {
                break;
            }
        }

        if ($orders->isEmpty()) {
            return response()->json(null);
        }

        $schedule = MonthlySchedule::forPeriodEnd($periodTo);

        return response()->json([
            'is_draft'         => true,
            'period_from'      => $periodFrom,
            'period_to'        => $periodTo,
            'issues_at'        => $schedule->proformaAt->toDateString(),
            'due_at'           => $schedule->paymentDue->toDateString(),
            ...ShopInvoice::amountsOf($orders),
            'currency'         => 'MKD',
            'orders_count'     => $orders->count(),
            'orders'           => $orders->values(),
        ]);
    }

    // GET /api/clinic/invoices/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $invoice = ShopInvoice::with(['orders.items'])
            ->where('shop_clinic_id', $clinic->id)
            ->findOrFail($id);

        return response()->json($invoice);
    }

    // GET /api/clinic/invoices/{id}/download.pdf
    public function downloadPdf(Request $request, int $id)
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $invoice = ShopInvoice::where('shop_clinic_id', $clinic->id)->findOrFail($id);

        return app(InvoiceDocument::class)->pdf($invoice)->download($invoice->pdfFileName());
    }
}
