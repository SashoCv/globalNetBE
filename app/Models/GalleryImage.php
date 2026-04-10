<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryImage extends Model
{
    protected $fillable = [
        'gallery_event_id',
        'path',
        'is_cover',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
        ];
    }

    public function galleryEvent(): BelongsTo
    {
        return $this->belongsTo(GalleryEvent::class);
    }
}
