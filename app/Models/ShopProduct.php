<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_vendor_id', 'shop_category_id',
        'name', 'slug', 'sku', 'kind',
        'price', 'cost_price', 'margin_percent', 'delivery_fee', 'currency', 'stock',
        'short_description', 'description', 'image',
        'status', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
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

    public function costTiers(): HasMany
    {
        return $this->hasMany(ShopProductCostTier::class)->orderBy('min_quantity');
    }

    /**
     * Per-unit cost for a given (aggregated) quantity: the unit_cost of the
     * highest tier whose min_quantity <= $qty. Falls back to cost_price when
     * there are no matching tiers.
     */
    public function effectiveUnitCost(int $qty): float
    {
        $tier = $this->costTiers
            ->filter(fn ($t) => $t->min_quantity <= $qty)
            ->sortByDesc('min_quantity')
            ->first();

        if ($tier) {
            return (float) $tier->unit_cost;
        }
        return (float) ($this->cost_price ?? 0);
    }
}
