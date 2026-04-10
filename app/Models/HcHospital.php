<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HcHospital extends Model
{
    protected $fillable = [
        'name',
        'city',
        'description',
        'specialties',
        'website',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
