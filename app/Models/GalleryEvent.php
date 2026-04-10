<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GalleryEvent extends Model
{
    protected $fillable = [
        'name',
        'category',
        'date',
        'location',
        'featured',
        'show_on_home',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'show_on_home' => 'boolean',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class);
    }

    public function coverImage(): HasOne
    {
        return $this->hasOne(GalleryImage::class)->where('is_cover', true);
    }
}
