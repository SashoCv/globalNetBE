<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_kotizacija_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'city',
        'hotel_stay',
        'hotel_name',
        'hotel_room',
        'hotel_notes',
        'consent_given',
        'consent_at',
        'consent_ip',
        'consent_user_agent',
        'consent_version',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'hotel_stay' => 'boolean',
            'consent_given' => 'boolean',
            'consent_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function kotizacija(): BelongsTo
    {
        return $this->belongsTo(EventKotizacija::class, 'event_kotizacija_id');
    }
}
