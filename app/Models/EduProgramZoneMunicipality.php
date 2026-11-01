<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: a municipality's zone in a program, for every language ('*') or as
 * an exception for one language.
 */
class EduProgramZoneMunicipality extends Model
{
    public const ALL = '*';

    protected $fillable = ['program_id', 'zone_id', 'municipality_id', 'language'];

    protected $attributes = ['language' => self::ALL];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'zone_id' => 'integer',
            'municipality_id' => 'integer',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(EduProgramZone::class, 'zone_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'municipality_id');
    }
}
