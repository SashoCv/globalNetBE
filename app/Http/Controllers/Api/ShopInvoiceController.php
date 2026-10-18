<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Services\Shop\InvoiceDocument;
use App\Services\Shop\InvoiceMailer;
use App\Services\Shop\ShopOrderSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopInvoiceController extends Controller
{
    // GET /api/shop-invoices
    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(5, (int) $request->get('per_page', 25)));

        $query = ShopInvoice::with('clinic:id,name,city,email,phone')
            ->withCount('orders');

        if ($request->filled('shop_clinic_id')) {
            $query->where('shop_clinic_id', $request->get('shop_clinic_id'));
        }

        if ($request->filled('status') && $request->get('status') !== 'all') {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('issued_at', '>=', $request->get('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('issued_at', '<=', $request->get('to'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('clinic', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $invoices = $query->orderByDesc('issued_at')->orderByDesc('id')->paginate($perPage);

        return response()->json($invoices);
    }

    // GET /api/shop-invoices/stats
    public function stats(Request $request): JsonResponse
    {
        $query = ShopInvoice::query();

        if ($request->filled('shop_clinic_id')) {
            $query->where('shop_clinic_id', $request->get('shop_clinic_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('issued_at', '>=', $request->get('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('issued_at', '<=', $request->get('to'));
        }

        $base = clone $query;

        $total = (clone $base)->count();
        $totalInvoiced = (float) (clone $base)->sum('total');
        $totalPaid = (float) (clone $base)->where('status', 'paid')->sum('total');
        $totalOutstanding = (float) (clone $base)->whereIn('status', ['pending', 'overdue'])->sum('total');

        $byStatus = (clone $base)
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => ['count' => (int) $row->count, 'total' => (float) $row->total]]);

        // Monthly breakdown (detailed financial report) — grouped by issue month.
        $monthly = (clone $base)
            ->select(
                DB::raw("strftime('%Y-%m', issued_at) as month"),
                DB::raw('count(*) as count'),
                DB::raw('sum(total) as total'),
                DB::raw("sum(case when status = 'paid' then total else 0 end) as paid"),
                // For the accountant's price levelling: what was sold below full price.
                DB::raw("sum(case when status != 'cancelled' then discount_amount else 0 end) as discount"),
                DB::raw("sum(case when status != 'cancelled' then credit_amount else 0 end) as credit")
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
                'paid' => (float) $row->paid,
                'discount' => (float) $row->discount,
                'credit' => (float) $row->credit,
            ]);

        return response()->json([
            'total' => $total,
            'total_invoiced' => $totalInvoiced,
            'total_paid' => $totalPaid,
            'total_outstanding' => $totalOutstanding,
            'by_status' => $byStatus,
            'monthly' => $monthly,
        ]);
    }

    // GET /api/shop-invoices/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $invoice = ShopInvoice::with(['clinic', 'orders.items.product', 'orders.orderModel'])
            ->findOrFail($id);

        return response()->json($invoice);
    }

    // POST /api/shop-invoices/generate
    // Automatically pulls un-invoiced orders for the given period, grouped by clinic,
    // and creates one invoice per clinic aggregating those orders.
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_from'    => 'required|date',
            'period_to'      => 'required|date|after_or_equal:period_from',
            'shop_clinic_id' => 'nullable|integer|exists:shop_clinics,id',
            'due_at'         => 'nullable|date',
        ]);

        $result = ShopInvoice::generateForPeriod(
            $validated['period_from'],
            $validated['period_to'],
            $validated['shop_clinic_id'] ?? null,
            $validated['due_at'] ?? null
        );

        if ($result['orders_count'] === 0) {
            return response()->json([
                'message' => 'Нема нефактурирани нарачки во избраниот период.',
                'invoices' => [],
            ], 422);
        }

        $mailer = app(InvoiceMailer::class);
        foreach ($result['invoices'] as $invoice) {
            $mailer->issued($invoice);
        }

        $invoiceIds = $result['invoices']->pluck('id');
        $invoices = ShopInvoice::with('clinic:id,name,city,email,phone')
            ->withCount('orders')
            ->whereIn('id', $invoiceIds)
            ->get();

        return response()->json([
            'message'  => $result['invoices']->count() . ' фактура(и) генерирани за ' . $result['orders_count'] . ' нарачки.',
            'invoices' => $invoices,
        ], 201);
    }

    // PATCH /api/shop-invoices/{id}/mark-paid
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => 'nullable|string|max:100',
        ]);

        $invoice = ShopInvoice::findOrFail($id);

        if ($invoice->orders()->where('payment_status', 'awaiting_discount')->exists()) {
            return response()->json(['message' => 'Збирниот попуст за оваа фактура сè уште не е одобрен.'], 422);
        }

        $wasPaid = $invoice->status === 'paid';

        app(ShopOrderSettlement::class)->invoicePaid($invoice, $validated['payment_method'] ?? null);

        if (! $wasPaid) {
            app(InvoiceMailer::class)->paid($invoice->fresh());
        }

        return response()->json($invoice->load('clinic'));
    }

    // PATCH /api/shop-invoices/{id}/cancel
    public function cancel(Request $request, int $id): JsonResponse
    {
        $invoice = ShopInvoice::findOrFail($id);
        $previous = $invoice->status;

        DB::transaction(function () use ($invoice) {
            ShopOrder::where('shop_invoice_id', $invoice->id)->update(['shop_invoice_id' => null]);
            $invoice->update(['status' => 'cancelled']);
        });

        if ($previous !== 'cancelled') {
            app(InvoiceMailer::class)->cancelled($invoice, $previous === 'paid');
        }

        return response()->json($invoice->load('clinic'));
    }

    // GET /api/shop-invoices/{id}/download.pdf
    public function downloadPdf(Request $request, int $id)
    {
        $invoice = ShopInvoice::findOrFail($id);

        return app(InvoiceDocument::class)->pdf($invoice)->download($invoice->pdfFileName());
    }
}
