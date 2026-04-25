<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use Illuminate\Http\JsonResponse;

class PublicPresentationController extends Controller
{
    // GET /api/presentation/{qrToken}
    public function show(string $qrToken): JsonResponse
    {
        $presentation = Presentation::where('qr_token', $qrToken)
            ->with('session.event:id,name')
            ->first();

        if (!$presentation) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        if (!$presentation->is_active) {
            return response()->json([
                'message' => 'Оваа презентација не е активна.',
                'ended' => true,
            ], 410);
        }

        return response()->json([
            'presentation' => [
                'id' => $presentation->id,
                'title' => $presentation->title,
                'subtitle' => $presentation->subtitle,
                'hero_image' => $presentation->hero_image,
                'content' => $presentation->content,
                'cta_text' => $presentation->cta_text,
                'cta_url' => $presentation->cta_url,
                'gallery' => $presentation->gallery,
            ],
            'session' => [
                'name' => $presentation->session->name,
                'logo' => $presentation->session->logo,
            ],
            'event' => [
                'name' => $presentation->session->event->name,
            ],
        ]);
    }
}
