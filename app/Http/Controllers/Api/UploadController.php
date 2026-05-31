<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * POST /api/uploads
     * Generic single-image upload (used by the admin image pickers). Stores the
     * file on the public disk under an optional `folder` and returns a
     * root-relative `src` ready for the frontend (e.g. /storage/uploads/x.jpg).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:8192',
            'folder' => 'nullable|string|max:100',
        ]);

        $folder = preg_replace('/[^a-z0-9\-_\/]/i', '', $request->input('folder', 'uploads')) ?: 'uploads';

        $path = $request->file('image')->store($folder, 'public');

        return response()->json([
            'src' => '/storage/'.$path,
            'path' => $path,
        ], 201);
    }
}
