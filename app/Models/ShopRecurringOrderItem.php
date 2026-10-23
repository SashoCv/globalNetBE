<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopRecurringOrderItem extends Model
{
    protected $fillable = ['shop_recurring_order_id', 'shop_product_id', 'quantity', 'locked_price'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'locked_price' => 'decimal:2',
        ];
    }

    public function recurringOrder(): BelongsTo
    {
        return $this->belongsTo(ShopRecurringOrder::class, 'shop_recurring_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    // The guaranteed price, or less when the product got cheaper since.
    public function unitPrice(): float
    {
        $current = (float) ($this->product?->price ?? $this->locked_price);

        return min((float) $this->locked_price, $current);
    }
}
