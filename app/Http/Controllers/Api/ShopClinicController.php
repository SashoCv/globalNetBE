<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopClinicController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    // GET /api/shop-clinics  (paginated)
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 5), 200);
        $sort = $request->query('sort', 'created_at');
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'name', 'status', 'city', 'reviewed_at'];
        if (!in_array($sort, $allowedSorts, true)) $sort = 'created_at';

        $query = ShopClinic::query()->orderBy($sort, $direction)->orderBy('id', 'desc');

        if ($status = $request->query('status')) {
            if (in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($city = $request->query('city')) {
            $query->where('city', $city);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('contact_person', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('edb', 'like', $like);
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // GET /api/shop-clinics/stats
    public function stats(): JsonResponse
    {
        $rows = ShopClinic::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $cities = ShopClinic::query()
            ->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as cnt')
            ->groupBy('city')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        return response()->json([
            'total' => $rows->sum(),
            'pending' => (int) ($rows['pending'] ?? 0),
            'approved' => (int) ($rows['approved'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'cities' => $cities,
        ]);
    }

    // POST /api/shop-clinics/bulk-approve  body: { ids: [], note?: '' }
    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|exists:shop_clinics,id',
            'note' => 'nullable|string',
        ]);
        $updates = ['status' => 'approved', 'reviewed_at' => now()];
        if (!empty($data['note'])) $updates['admin_note'] = $data['note'];
        $count = ShopClinic::whereIn('id', $data['ids'])->update($updates);
        return response()->json(['updated' => $count]);
    }

    // POST /api/shop-clinics/bulk-reject  body: { ids: [], note?: '' }
    public function bulkReject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|exists:shop_clinics,id',
            'note' => 'nullable|string',
        ]);
        $updates = ['status' => 'rejected', 'reviewed_at' => now()];
        if (!empty($data['note'])) $updates['admin_note'] = $data['note'];
        $count = ShopClinic::whereIn('id', $data['ids'])->update($updates);
        return response()->json(['updated' => $count]);
    }

    // GET /api/shop-clinics/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json(ShopClinic::findOrFail($id));
    }

    // POST /api/shop-clinics (admin direct create — also used by public submit endpoint)
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $clinic = ShopClinic::create($validated);
        return response()->json($clinic, 201);
    }

    // PUT /api/shop-clinics/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $clinic = ShopClinic::findOrFail($id);
        $validated = $this->validatePayload($request, $clinic);
        $clinic->update($validated);
        return response()->json($clinic->fresh());
    }

    // POST /api/shop-clinics/{id}/approve
    public function approve(Request $request, int $id): JsonResponse
    {
        $clinic = ShopClinic::findOrFail($id);
        $clinic->update([
            'status' => 'approved',
            'admin_note' => $request->input('note') ?: $clinic->admin_note,
            'reviewed_at' => now(),
        ]);
        return response()->json($clinic->fresh());
    }

    // POST /api/shop-clinics/{id}/reject
    public function reject(Request $request, int $id): JsonResponse
    {
        $clinic = ShopClinic::findOrFail($id);
        $clinic->update([
            'status' => 'rejected',
            'admin_note' => $request->input('note') ?: $clinic->admin_note,
            'reviewed_at' => now(),
        ]);
        return response()->json($clinic->fresh());
    }

    // DELETE /api/shop-clinics/{id}
    public function destroy(int $id): JsonResponse
    {
        ShopClinic::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // POST /api/public/shop-clinics — public registration
    public function publicStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'edb' => 'nullable|string|max:32',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:shop_clinics,email',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:500',
        ]);
        $validated['status'] = 'pending';
        $clinic = ShopClinic::create($validated);
        return response()->json([
            'message' => 'Барањето е примено. Ќе ве известиме по одобрување.',
            'id' => $clinic->id,
        ], 201);
    }

    private function validatePayload(Request $request, ?ShopClinic $existing = null): array
    {
        $rule = $existing ? 'sometimes' : 'required';
        return $request->validate([
            'name' => "$rule|string|max:255",
            'slug' => 'nullable|string|max:255|unique:shop_clinics,slug' . ($existing ? ',' . $existing->id : ''),
            'edb' => 'nullable|string|max:32',
            'contact_person' => 'nullable|string|max:255',
            'email' => "$rule|email|max:255|unique:shop_clinics,email" . ($existing ? ',' . $existing->id : ''),
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:500',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
            'admin_note' => 'nullable|string',
        ]);
    }
}
