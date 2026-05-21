<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_order_id', 'shop_product_id', 'shop_vendor_id',
        'product_name', 'product_sku', 'kind',
        'unit_cost_price', 'unit_sale_price', 'quantity',
        'subtotal', 'cost_subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost_price' => 'decimal:2',
            'unit_sale_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cost_subtotal' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(ShopVendor::class, 'shop_vendor_id');
    }
}
