<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: a school in a program with its participant counts per language.
 * count_total always equals the sum of the three counts.
 */
class EduProgramSchool extends Model
{
    public const SOURCES = ['import', 'manual', 'persons'];

    protected $fillable = ['program_id', 'school_id', 'count_mk', 'count_sq', 'count_tr', 'count_total', 'comment', 'source'];

    protected $attributes = [
        'count_mk' => 0,
        'count_sq' => 0,
        'count_tr' => 0,
        'count_total' => 0,
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'school_id' => 'integer',
            'count_mk' => 'integer',
            'count_sq' => 'integer',
            'count_tr' => 'integer',
            'count_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EduProgramSchool $row) {
            $row->count_total = (int) $row->count_mk + (int) $row->count_sq + (int) $row->count_tr;
        });
    }

    /** @return array{mk: int, sq: int, tr: int} */
    public function counts(): array
    {
        return ['mk' => (int) $this->count_mk, 'sq' => (int) $this->count_sq, 'tr' => (int) $this->count_tr];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EduProgram::class, 'program_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(EduSchool::class, 'school_id');
    }
}
