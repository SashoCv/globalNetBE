<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'location',
        'status',
        'total_participants',
        'qr_token',
        'registration_open',
        'registration_redirect_url',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_open' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            if (empty($event->qr_token)) {
                $event->qr_token = (string) Str::uuid();
            }
        });
    }

    public function kotizacii(): HasMany
    {
        return $this->hasMany(EventKotizacija::class)->orderBy('sort_order');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class);
    }

    public function attendees(): HasManyThrough
    {
        return $this->hasManyThrough(
            EventAttendee::class,
            EventAttendance::class,
            'event_session_id',
            'id',
            'id',
            'event_attendee_id'
        )->whereIn('event_attendance.event_session_id', function ($query) {
            $query->select('id')
                ->from('event_sessions')
                ->whereColumn('event_sessions.event_id', 'events.id');
        })->distinct();
    }
}
