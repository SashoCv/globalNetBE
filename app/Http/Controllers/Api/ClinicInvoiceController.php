<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
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
    // Live preview of the invoice currently being formed for this billing cycle
    // (the 20th of last month to the 19th). Not a real ShopInvoice yet — nothing
    // is persisted, it just reflects the clinic's not-yet-invoiced orders so far.
    // It becomes a real, payable invoice automatically on the 20th.
    public function draft(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        [$periodFrom, $periodTo] = ShopInvoice::billingPeriod(now());

        $orders = ShopOrder::with('orderModel:id,code,label,title')
            ->where('shop_clinic_id', $clinic->id)
            ->whereNull('shop_invoice_id')
            ->whereNull('shop_order_cycle_id')
            ->whereNotIn('status', ['cancelled'])
            ->whereHas('items', fn ($q) => $q->where('kind', 'product'))
            ->whereBetween('placed_at', ShopInvoice::placedBetween($periodFrom, $periodTo))
            ->orderByDesc('placed_at')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(null);
        }

        return response()->json([
            'is_draft'         => true,
            'period_from'      => $periodFrom,
            'period_to'        => $periodTo,
            'subtotal'         => round((float) $orders->sum('subtotal'), 2),
            'surcharge_amount' => round((float) $orders->sum('surcharge_amount'), 2),
            'total'            => round((float) $orders->sum('total'), 2),
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
