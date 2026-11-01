<?php

namespace App\Models;

use App\Models\Concerns\StoresUnicodeJson;
use App\Support\Edu\EduLanguage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Обуки: a training program (project) — the root of per-school counts,
 * participants, zones, groups and the outputs. Master data (schools,
 * trainers, municipalities…) is shared by all programs.
 */
class EduProgram extends Model
{
    use StoresUnicodeJson;

    public const LEVELS = EduSchool::LEVELS;

    public const STAFF_TYPES = ['teachers', 'career_counselors', 'professional_associates'];
    public const STAFF_LABELS = [
        'teachers' => 'Наставници',
        'career_counselors' => 'Кариерни советници',
        'professional_associates' => 'Стручни соработници',
    ];
    /** As printed in "Присутни {кадар} од училиште". */
    public const STAFF_PLURAL = [
        'teachers' => 'наставници',
        'career_counselors' => 'кариерни советници',
        'professional_associates' => 'стручни соработници',
    ];

    public const ASSIGN_MODES = ['auto', 'manual'];
    public const ASSIGN_MODE_LABELS = ['auto' => 'Автоматски', 'manual' => 'Рачно'];

    public const STATUSES = ['draft', 'final'];
    public const STATUS_LABELS = ['draft' => 'Нацрт', 'final' => 'Финален'];

    public const MODE_COUNTS = 'counts';
    public const MODE_PERSONS = 'persons';
    public const PARTICIPANT_MODES = [self::MODE_COUNTS, self::MODE_PERSONS];
    public const PARTICIPANT_MODE_LABELS = [self::MODE_COUNTS => 'Бројки по училиште', self::MODE_PERSONS => 'Листа на учесници'];

    protected $fillable = [
        'title', 'topic_id', 'school_level', 'staff_type', 'group_size_min', 'group_size_max',
        'trainer_assignment_mode', 'participant_mode', 'language_map', 'period_label',
        'default_start_time', 'default_end_time', 'default_days', 'status', 'created_by',
    ];

    protected $attributes = [
        'trainer_assignment_mode' => 'auto',
        'participant_mode' => self::MODE_COUNTS,
        'default_days' => 2,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'topic_id' => 'integer',
            'group_size_min' => 'integer',
            'group_size_max' => 'integer',
            'default_days' => 'integer',
            'language_map' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EduProgram $program) {
            $program->language_map = self::normalizeLanguageMap($program->language_map);
        });

        // edu_imports.program_id has no foreign key (see its migration):
        // remove the program's imports and their files here.
        static::deleting(function (EduProgram $program) {
            $disk = Storage::disk(config('edu.imports_disk', 'local'));
            foreach (EduImport::where('program_id', $program->id)->get() as $import) {
                foreach (array_filter([$import->path, $import->preview_path]) as $path) {
                    $disk->delete($path);
                }
                $import->delete();
            }
        });
    }

    /**
     * The default map with the given entries on top; every language maps to a
     * known language.
     *
     * @return array{mk: string, sq: string, tr: string}
     */
    public static function normalizeLanguageMap(?array $map): array
    {
        $out = [];
        $defaults = config('edu.default_language_map', ['mk' => 'mk', 'sq' => 'sq', 'tr' => 'mk']);
        foreach (EduLanguage::CODES as $code) {
            $value = $map[$code] ?? $defaults[$code] ?? $code;
            $out[$code] = EduLanguage::isValid($value) ? $value : $code;
        }

        return $out;
    }

    /** The language participants of $language are grouped in. */
    public function groupLanguage(string $language): string
    {
        return ($this->language_map ?? [])[$language] ?? $language;
    }

    public function isPersons(): bool
    {
        return $this->participant_mode === self::MODE_PERSONS;
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(EduTopic::class, 'topic_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(EduProgramZone::class, 'program_id');
    }

    public function zoneMunicipalities(): HasMany
    {
        return $this->hasMany(EduProgramZoneMunicipality::class, 'program_id');
    }

    public function schools(): HasMany
    {
        return $this->hasMany(EduProgramSchool::class, 'program_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(EduGroup::class, 'program_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EduParticipant::class, 'program_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(EduImport::class, 'program_id');
    }
}
