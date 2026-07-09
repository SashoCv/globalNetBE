<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClinicOrderRequestController extends Controller
{
    // POST /api/clinic/requests
    public function store(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $validated = $request->validate([
            'type'          => 'required|string|in:wrong_quantity,missing_item,product_request,other',
            'shop_order_id' => [
                'nullable',
                'integer',
                Rule::exists('shop_orders', 'id')->where('shop_clinic_id', $clinic->id),
            ],
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ]);

        $orderRequest = ShopOrderRequest::create([
            'shop_clinic_id' => $clinic->id,
            'shop_order_id'  => $validated['shop_order_id'] ?? null,
            'type'           => $validated['type'],
            'subject'        => $validated['subject'] ?? null,
            'message'        => $validated['message'],
            'status'         => 'new',
        ]);

        return response()->json($orderRequest->load('order'), 201);
    }

    // GET /api/clinic/requests
    public function index(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $requests = ShopOrderRequest::with('order')
            ->where('shop_clinic_id', $clinic->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($requests);
    }

    // GET /api/clinic/requests/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $orderRequest = ShopOrderRequest::with('order')
            ->where('shop_clinic_id', $clinic->id)
            ->findOrFail($id);

        return response()->json($orderRequest);
    }
}
