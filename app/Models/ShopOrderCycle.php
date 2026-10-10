<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One week of the weekly group-order model (Неделни збирни нарачки).
 *
 * open → closed (Mon 12:00, waiting for the group discount)
 *      → payment_open (discount approved, clinics pay until Tue 14:00)
 *      → payment_closed (paid orders confirmed, unpaid ones cancelled)
 */
class ShopOrderCycle extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_PAYMENT_OPEN = 'payment_open';
    public const STATUS_PAYMENT_CLOSED = 'payment_closed';

    protected $fillable = [
        'code', 'shop_order_model_id',
        'opens_at', 'cutoff_at', 'discount_due_at', 'reminder_at', 'payment_due_at',
        'delivery_from', 'delivery_to',
        'status', 'extra_discount_percent', 'discount_approved_at', 'discount_approved_by', 'auto_approved',
        'closed_at', 'reminder_sent_at', 'payment_closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'cutoff_at' => 'datetime',
            'discount_due_at' => 'datetime',
            'reminder_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'delivery_from' => 'date:Y-m-d',
            'delivery_to' => 'date:Y-m-d',
            'extra_discount_percent' => 'decimal:2',
            'discount_approved_at' => 'datetime',
            'auto_approved' => 'boolean',
            'closed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'payment_closed_at' => 'datetime',
        ];
    }

    public function orderModel(): BelongsTo
    {
        return $this->belongsTo(ShopOrderModel::class, 'shop_order_model_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }
}
