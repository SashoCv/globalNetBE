<?php

namespace App\Models;

use App\Models\Concerns\StoresUnicodeJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обуки: a municipality, the unit trainings are grouped by. Training centres
 * (is_training_hub) are municipalities too; every municipality points at its
 * nearest centre through hub_municipality_id (a centre at itself).
 */
class EduMunicipality extends Model
{
    use StoresUnicodeJson;

    protected $fillable = [
        'name', 'name_sq', 'region_id', 'is_training_hub', 'hub_municipality_id',
        'hub_short_name', 'part_of_skopje', 'aliases',
    ];

    protected function casts(): array
    {
        return [
            'is_training_hub' => 'boolean',
            'part_of_skopje' => 'boolean',
            'aliases' => 'array',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(EduRegion::class, 'region_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(self::class, 'hub_municipality_id');
    }

    /** Municipalities whose nearest training centre is this one. */
    public function served(): HasMany
    {
        return $this->hasMany(self::class, 'hub_municipality_id');
    }

    public function schools(): HasMany
    {
        return $this->hasMany(EduSchool::class, 'municipality_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(EduLocation::class, 'municipality_id');
    }
}
