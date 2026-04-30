<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventRegistrationController extends Controller
{
    // GET /api/events/{id}/registrations
    public function index(int $eventId): JsonResponse
    {
        Event::findOrFail($eventId);
        $rows = EventRegistration::where('event_id', $eventId)
            ->with('kotizacija:id,name,price,currency')
            ->orderByDesc('registered_at')
            ->get();
        return response()->json($rows);
    }

    // DELETE /api/event-registrations/{id}
    public function destroy(int $id): JsonResponse
    {
        EventRegistration::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // GET /api/events/{id}/registrations/export
    public function export(int $eventId): StreamedResponse
    {
        $event = Event::findOrFail($eventId);
        $rows = EventRegistration::where('event_id', $eventId)
            ->with('kotizacija:id,name')
            ->orderBy('registered_at')
            ->get();

        $filename = 'registracii_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $event->name) . '_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Регистриран', 'Име', 'Презиме', 'Е-пошта', 'Телефон', 'Град',
                'Котизација', 'Сместен во хотел', 'Хотел', 'Соба', 'Забелешка',
                'Согласност', 'Согласност во', 'IP', 'Верзија'
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    optional($r->registered_at)->format('Y-m-d H:i'),
                    $r->first_name,
                    $r->last_name,
                    $r->email,
                    $r->phone,
                    $r->city,
                    optional($r->kotizacija)->name,
                    $r->hotel_stay ? 'Да' : 'Не',
                    $r->hotel_name,
                    $r->hotel_room,
                    $r->hotel_notes,
                    $r->consent_given ? 'Да' : 'Не',
                    optional($r->consent_at)->format('Y-m-d H:i'),
                    $r->consent_ip,
                    $r->consent_version,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
