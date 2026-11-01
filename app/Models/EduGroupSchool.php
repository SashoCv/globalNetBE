<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: an allocation — participant_count participants of a school, in
 * their own language, attend the group (0 = invited school).
 */
class EduGroupSchool extends Model
{
    protected $fillable = ['group_id', 'school_id', 'language', 'participant_count', 'sort_order'];

    protected $attributes = ['participant_count' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'school_id' => 'integer',
            'participant_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EduGroup::class, 'group_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(EduSchool::class, 'school_id');
    }
}
