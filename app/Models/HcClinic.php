<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HcClinic extends Model
{
    protected $fillable = [
        'name',
        'city',
        'specialties',
        'phone',
        'address',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
