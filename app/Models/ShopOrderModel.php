<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrderModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'label', 'title', 'tagline', 'description', 'bullets',
        'icon', 'accent_color', 'accent_color_soft',
        'cutoff_day', 'cutoff_hour', 'delivery_day', 'max_delivery_hours',
        'repeat_months', 'auto_repeat',
        'min_order_amount', 'surcharge_percent', 'surcharge_fixed_amount',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'bullets' => 'array',
            'cutoff_day' => 'integer',
            'cutoff_hour' => 'integer',
            'delivery_day' => 'integer',
            'max_delivery_hours' => 'integer',
            'repeat_months' => 'integer',
            'auto_repeat' => 'boolean',
            'min_order_amount' => 'decimal:2',
            'surcharge_percent' => 'decimal:2',
            'surcharge_fixed_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
