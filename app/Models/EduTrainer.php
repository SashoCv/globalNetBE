<?php

namespace App\Models;

use App\Models\Concerns\StoresUnicodeJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обуки: a trainer. full_name is Cyrillic; full_name_sq is the Latin form
 * printed for Albanian groups. An empty languages list means unknown.
 */
class EduTrainer extends Model
{
    use StoresUnicodeJson;

    protected $fillable = [
        'full_name', 'full_name_sq', 'name_key', 'aliases', 'languages',
        'home_municipality_id', 'home_city_text', 'education', 'phone', 'email', 'notes', 'is_active',
    ];

    protected $attributes = [
        'languages' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'languages' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function homeMunicipality(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'home_municipality_id');
    }

    /** Eligibility matrix rows (topic_id null = the default matrix). */
    public function hubs(): HasMany
    {
        return $this->hasMany(EduTrainerHub::class, 'trainer_id');
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(EduTopic::class, 'edu_topic_trainers', 'trainer_id', 'topic_id');
    }

    /** Groups (of any program) the trainer is assigned to. */
    public function groups(): HasMany
    {
        return $this->hasMany(EduGroup::class, 'trainer_id');
    }
}
