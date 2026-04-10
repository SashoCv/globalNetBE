<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EventAttendee extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'city',
        'license_number',
    ];

    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(EventSession::class, 'event_attendance')
            ->withPivot('checked_in_at');
    }
}
