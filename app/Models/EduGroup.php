<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обуки: a training group ("Група {number}") of a program. language is the
 * language the group is held in; its allocations keep each school's real
 * participant language.
 */
class EduGroup extends Model
{
    public const STATUSES = ['draft', 'planned', 'held', 'cancelled'];
    public const STATUS_LABELS = ['draft' => 'Нацрт', 'planned' => 'Закажана', 'held' => 'Одржана', 'cancelled' => 'Откажана'];

    protected $fillable = [
        'program_id', 'number', 'zone_id', 'language', 'location_id', 'location_text', 'venue_municipality_id',
        'trainer_id', 'trainer_override', 'start_date', 'end_date', 'status', 'is_locked', 'comment',
    ];

    protected $attributes = [
        'status' => 'draft',
        'is_locked' => false,
        'trainer_override' => false,
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'number' => 'integer',
            'zone_id' => 'integer',
            'location_id' => 'integer',
            'venue_municipality_id' => 'integer',
            'trainer_id' => 'integer',
            'trainer_override' => 'boolean',
            'is_locked' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EduProgram::class, 'program_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(EduProgramZone::class, 'zone_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(EduLocation::class, 'location_id');
    }

    public function venueMunicipality(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'venue_municipality_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(EduTrainer::class, 'trainer_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EduGroupSession::class, 'group_id')->orderBy('date');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(EduGroupSchool::class, 'group_id')->orderBy('sort_order')->orderBy('id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EduParticipant::class, 'group_id');
    }
}
