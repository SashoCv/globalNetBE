<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProductCostTier extends Model
{
    protected $fillable = [
        'shop_product_id',
        'min_quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }
}
