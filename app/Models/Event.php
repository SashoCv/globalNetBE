<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
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
