<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-month order (МОДЕЛ 04). Its items are the template for every month;
 * each month's order is a normal ShopOrder that points back here.
 */
class ShopRecurringOrder extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shop_clinic_id', 'shop_order_model_id', 'months_total', 'status',
        'delivery_contact', 'delivery_phone', 'delivery_email',
        'delivery_city', 'delivery_address', 'delivery_notes',
        'cancel_requested_at', 'cancel_request_note', 'cancelled_at', 'cancel_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'months_total' => 'integer',
            'cancel_requested_at' => 'datetime',
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
        return $this->hasMany(ShopRecurringOrderItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    // Months already ordered; cancelled months still count, they were due.
    public function monthsOrdered(): int
    {
        return (int) ($this->orders()->max('recurring_month') ?? 0);
    }
}
