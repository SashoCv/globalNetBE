<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShopProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_vendor_id', 'shop_category_id',
        'name', 'slug', 'sku', 'kind',
        'price', 'cost_price', 'currency', 'stock',
        'short_description', 'description', 'image',
        'status', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock' => 'integer',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShopProduct $p) {
            if (empty($p->slug)) {
                $p->slug = self::uniqueSlugForVendor($p->name, $p->shop_vendor_id);
            }
        });
        static::updating(function (ShopProduct $p) {
            if (($p->isDirty('name') || $p->isDirty('shop_vendor_id')) && !$p->isDirty('slug')) {
                $p->slug = self::uniqueSlugForVendor($p->name, $p->shop_vendor_id, $p->id);
            }
        });
    }

    private static function uniqueSlugForVendor(string $name, int $vendorId, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;
        while (self::where('shop_vendor_id', $vendorId)
            ->where('slug', $slug)
            ->when($ignore, fn($q) => $q->where('id', '!=', $ignore))
            ->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(ShopVendor::class, 'shop_vendor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'shop_category_id');
    }
}
