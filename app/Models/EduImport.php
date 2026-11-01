<?php

namespace App\Models;

use App\Models\Concerns\StoresUnicodeJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: one Excel import (upload → preview → resolve rows → apply).
 * The uploaded file and the preview JSON live on the local disk under
 * edu-imports/.
 */
class EduImport extends Model
{
    use StoresUnicodeJson;

    public const TYPE_WORKBOOK = 'workbook';
    public const TYPE_PARTICIPANTS = 'participants';
    public const TYPE_SCHOOL_COUNTS = 'school_counts';
    public const TYPES = [self::TYPE_WORKBOOK, self::TYPE_PARTICIPANTS, self::TYPE_SCHOOL_COUNTS];

    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'program_id', 'type', 'original_name', 'path', 'status', 'summary', 'preview_path', 'options',
        'created_by', 'applied_at',
    ];

    protected $hidden = ['path', 'preview_path'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'options' => 'array',
            'program_id' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /** Participant and school-count imports belong to a program (no FK, see the migration). */
    public function program(): BelongsTo
    {
        return $this->belongsTo(EduProgram::class, 'program_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
