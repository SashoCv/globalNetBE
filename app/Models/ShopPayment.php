<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ShopPayment extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_REDIRECTED = 'redirected';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reference',
        'payable_type',
        'payable_id',
        'shop_clinic_id',
        'amount',
        'currency',
        'status',
        'details1',
        'cpay_payment_ref',
        'request_payload',
        'response_payload',
        'failure_reason',
        'redirected_at',
        'paid_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'redirected_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ShopClinic::class, 'shop_clinic_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }
}
