<?php

namespace App\Models;

use App\Models\Concerns\StoresUnicodeJson;
use App\Services\Edu\EduText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обуки: a primary or secondary school, shared by every training program.
 *
 * display_name is the cleaned original (bilingual form and village included)
 * and is what outputs print; name / name_sq are its halves; name_key is only
 * for matching and keeps the village.
 */
class EduSchool extends Model
{
    use StoresUnicodeJson;

    public const LEVEL_PRIMARY = 'primary';
    public const LEVEL_SECONDARY = 'secondary';
    public const LEVELS = [self::LEVEL_PRIMARY, self::LEVEL_SECONDARY];
    public const LEVEL_LABELS = [self::LEVEL_PRIMARY => 'Основни училишта', self::LEVEL_SECONDARY => 'Средни училишта'];

    protected $fillable = [
        'level', 'source_no', 'display_name', 'name', 'name_sq', 'type_prefix', 'settlement', 'name_key',
        'municipality_id', 'address', 'address_sq', 'languages', 'phones', 'phone_raw',
        'director_name', 'director_contact', 'emails', 'email_raw', 'schools_mk_email', 'comment', 'is_active',
    ];

    protected $attributes = [
        'languages' => '[]',
        'phones' => '[]',
        'emails' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'source_no' => 'integer',
            'languages' => 'array',
            'phones' => 'array',
            'emails' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'municipality_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(EduLocation::class, 'school_id');
    }

    /**
     * The name fields derived from display_name: the Macedonian / Albanian
     * halves, type prefix, village and matching key.
     *
     * @return array{display_name: string, name: string, name_sq: string|null, type_prefix: string|null, settlement: string|null, name_key: string}
     */
    public static function nameFields(string $displayName, ?string $municipalityName): array
    {
        $display = EduText::clean($displayName);
        [$name, $nameSq] = EduText::splitBilingual($display);

        return [
            'display_name' => $display,
            'name' => $name,
            'name_sq' => $nameSq,
            'type_prefix' => EduText::typePrefix($name),
            'settlement' => EduText::settlement($name),
            'name_key' => EduText::key($name, $municipalityName),
        ];
    }
}
