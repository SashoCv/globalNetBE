<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopVendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'edb',
        'slug',
        'contact_person',
        'email',
        'phone',
        'city',
        'address',
        'description',
        'logo',
        'website',
        'status',
        'products_count',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'products_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShopVendor $v) {
            if (empty($v->slug)) {
                $v->slug = self::generateUniqueSlug($v->name);
            }
        });

        static::updating(function (ShopVendor $v) {
            if ($v->isDirty('name') && !$v->isDirty('slug')) {
                $v->slug = self::generateUniqueSlug($v->name, $v->id);
            }
        });
    }

    private static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'vendor';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class)->orderBy('sort_order')->orderBy('id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ShopCategory::class,
            'shop_vendor_categories',
            'shop_vendor_id',
            'shop_category_id'
        )->withTimestamps();
    }
}
