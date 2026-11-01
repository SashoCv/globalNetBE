<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: a place trainings are held at (a school, a hotel or other).
 */
class EduLocation extends Model
{
    public const KINDS = ['school', 'hotel', 'other'];
    public const KIND_LABELS = ['school' => 'Училиште', 'hotel' => 'Хотел', 'other' => 'Друго'];

    protected $fillable = [
        'name', 'kind', 'school_id', 'municipality_id', 'address', 'contact', 'capacity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(EduSchool::class, 'school_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'municipality_id');
    }
}
