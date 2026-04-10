<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HcBooking extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'specialty',
        'description',
        'documents',
        'hospital',
        'preferred_date',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'documents' => 'array',
        ];
    }
}
