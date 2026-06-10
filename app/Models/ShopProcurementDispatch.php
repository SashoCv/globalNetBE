<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProcurementDispatch extends Model
{
    protected $fillable = [
        'shop_vendor_id', 'vendor_name', 'to_email', 'status', 'scope',
        'date_from', 'date_to', 'total_quantity', 'total_cost',
        'items', 'note', 'emailed', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'total_quantity' => 'integer',
            'total_cost' => 'decimal:2',
            'items' => 'array',
            'emailed' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(ShopVendor::class, 'shop_vendor_id');
    }
}
