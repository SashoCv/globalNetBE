<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'shop_clinic_id', 'shop_order_model_id', 'status',
        'subtotal', 'cost_subtotal', 'surcharge_amount', 'total', 'currency',
        'delivery_contact', 'delivery_phone', 'delivery_email',
        'delivery_city', 'delivery_address', 'delivery_notes',
        'placed_at', 'requested_delivery_date', 'next_recurrence_date',
        'cancelled_at', 'completed_at', 'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'cost_subtotal' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'requested_delivery_date' => 'date',
            'next_recurrence_date' => 'date',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ShopClinic::class, 'shop_clinic_id');
    }

    public function orderModel(): BelongsTo
    {
        return $this->belongsTo(ShopOrderModel::class, 'shop_order_model_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    // Profit = (sale subtotal − cost subtotal). Doesn't include surcharge (that's revenue, not margin).
    public function getProfitAttribute(): float
    {
        return (float) ($this->subtotal - $this->cost_subtotal);
    }
}
