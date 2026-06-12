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
        'show_on_home',
        'show_on_hero',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
            'show_on_home' => 'boolean',
            'show_on_hero' => 'boolean',
        ];
    }

    /**
     * Expose `src` and `cover` so the frontend (admin + public) gets a ready
     * root-relative URL and a plain boolean, regardless of how `path`/`is_cover`
     * are stored.
     */
    protected $appends = ['src', 'cover'];

    public function getSrcAttribute(): string
    {
        $path = $this->path ?? '';
        if ($path === '' || str_starts_with($path, 'http')) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }

    public function getCoverAttribute(): bool
    {
        return (bool) $this->is_cover;
    }

    public function galleryEvent(): BelongsTo
    {
        return $this->belongsTo(GalleryEvent::class);
    }
}
