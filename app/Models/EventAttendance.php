<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendance extends Model
{
    public $timestamps = false;

    protected $table = 'event_attendance';

    protected $fillable = [
        'event_session_id',
        'event_attendee_id',
        'checked_in_at',
        'phone',
        'consent_given',
        'consent_at',
        'consent_ip',
        'consent_user_agent',
        'consent_version',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'consent_given' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'event_session_id');
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(EventAttendee::class, 'event_attendee_id');
    }
}
