<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopClinicWalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicWalletController extends Controller
{
    // GET /api/clinic/wallet
    public function index(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $transactions = ShopClinicWalletTransaction::where('shop_clinic_id', $clinic->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'wallet_balance' => (float) $clinic->wallet_balance,
            'transactions'   => $transactions,
        ]);
    }
}
