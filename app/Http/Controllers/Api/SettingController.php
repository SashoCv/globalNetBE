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
            'settings.*.group' => 'nullable|string',
            'settings.*.locale' => 'nullable|string',
        ]);

        foreach ($request->settings as $item) {
            $attributes = ['value' => $item['value'] ?? null];
            if (!empty($item['group'])) {
                $attributes['group'] = $item['group'];
            }

            Setting::updateOrCreate(
                ['key' => $item['key'], 'locale' => $item['locale'] ?? 'mk'],
                $attributes
            );
        }

        $settings = Setting::all();

        return response()->json($settings);
    }

    /**
     * GET /api/settings/public/{group}?locale=xx (PUBLIC)
     * Get settings for a specific group for frontend.
     *
     * Macedonian ('mk') is the base locale. For any other locale we overlay its
     * non-empty values on top of the mk map, so untranslated keys fall back to
     * Macedonian automatically.
     */
    public function public(string $group, Request $request): JsonResponse
    {
        $locale = $request->query('locale', 'mk');

        $base = Setting::where('group', $group)
            ->where('locale', 'mk')
            ->pluck('value', 'key');

        if ($locale !== 'mk') {
            $overlay = Setting::where('group', $group)
                ->where('locale', $locale)
                ->pluck('value', 'key');

            foreach ($overlay as $key => $value) {
                if ($value !== null && $value !== '') {
                    $base[$key] = $value;
                }
            }
        }

        return response()->json($base);
    }
}
