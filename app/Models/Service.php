<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'name',
        'color',
        'description',
        'details',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function bullets(): HasMany
    {
        return $this->hasMany(ServiceBullet::class)->orderBy('sort_order');
    }
}
