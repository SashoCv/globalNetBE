<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EventSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_session_type_id',
        'name',
        'description',
        'start_time',
        'end_time',
        'qr_token',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventSession $session) {
            if (empty($session->qr_token)) {
                $session->qr_token = (string) Str::uuid();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EventSessionType::class, 'event_session_type_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(EventAttendance::class);
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(EventAttendee::class, 'event_attendance')
            ->withPivot('checked_in_at', 'phone');
    }
}
