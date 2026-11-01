<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: one training day of a group. Times are plain 'H:i' strings.
 */
class EduGroupSession extends Model
{
    protected $fillable = ['group_id', 'date', 'start_time', 'end_time', 'sort_order'];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'date' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EduGroup::class, 'group_id');
    }
}
