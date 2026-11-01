<?php

namespace App\Models;

use App\Models\Concerns\StoresUnicodeJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: a participant of a program ("persons" mode). language is the
 * participant's own language; the group may be held in another one.
 */
class EduParticipant extends Model
{
    use StoresUnicodeJson;

    protected $fillable = [
        'program_id', 'school_id', 'school_text', 'municipality_id', 'language', 'full_name', 'name_key',
        'email', 'phone', 'position', 'extra', 'group_id', 'import_id',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'school_id' => 'integer',
            'municipality_id' => 'integer',
            'group_id' => 'integer',
            'import_id' => 'integer',
            'extra' => 'array',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EduProgram::class, 'program_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(EduSchool::class, 'school_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'municipality_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EduGroup::class, 'group_id');
    }
}
