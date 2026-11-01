<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обуки: a grouping zone of one program (starts as a copy of a region).
 */
class EduProgramZone extends Model
{
    protected $fillable = ['program_id', 'region_id', 'name', 'sort_order'];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'region_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EduProgram::class, 'program_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(EduRegion::class, 'region_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(EduProgramZoneMunicipality::class, 'zone_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(EduGroup::class, 'zone_id');
    }
}
