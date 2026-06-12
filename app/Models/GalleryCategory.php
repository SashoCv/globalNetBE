<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GalleryCategory extends Model
{
    protected $fillable = [
        'name',
        'name_en',
        'slug',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Slug is generated once on creation and never changes afterwards, so
        // gallery events that reference it by slug keep working.
        static::creating(function (GalleryCategory $c) {
            if (empty($c->slug)) {
                $c->slug = self::uniqueSlug($c->name);
            }
        });
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}
