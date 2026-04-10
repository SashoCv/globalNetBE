<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /api/settings
     * List all settings with optional group filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Setting::query();

        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        $settings = $query->get();

        return response()->json($settings);
    }

    /**
     * PUT /api/settings
     * Bulk update settings. Accepts array of {key, value} pairs.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable|string',
        ]);

        foreach ($request->settings as $item) {
            Setting::updateOrCreate(
                ['key' => $item['key']],
                ['value' => $item['value'] ?? null]
            );
        }

        $settings = Setting::all();

        return response()->json($settings);
    }

    /**
     * GET /api/settings/public/{group} (PUBLIC)
     * Get settings for a specific group for frontend.
     */
    public function public(string $group): JsonResponse
    {
        $settings = Setting::where('group', $group)->get();

        // Return as key-value object for easy frontend consumption
        $result = $settings->pluck('value', 'key');

        return response()->json($result);
    }
}
