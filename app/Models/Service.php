<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'name',
        'name_en',
        'color',
        'description',
        'description_en',
        'details',
        'details_en',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'details_en' => 'array',
        ];
    }

    public function bullets(): HasMany
    {
        return $this->hasMany(ServiceBullet::class)->orderBy('sort_order');
    }
}
