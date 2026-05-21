<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'kind', 'icon', 'color',
        'description', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShopCategory $c) {
            if (empty($c->slug)) {
                $c->slug = self::uniqueSlug($c->name);
            }
        });
        static::updating(function (ShopCategory $c) {
            if ($c->isDirty('name') && !$c->isDirty('slug')) {
                $c->slug = self::uniqueSlug($c->name, $c->id);
            }
        });
    }

    private static function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->when($ignore, fn($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(
            ShopVendor::class,
            'shop_vendor_categories',
            'shop_category_id',
            'shop_vendor_id'
        )->withTimestamps();
    }
}
