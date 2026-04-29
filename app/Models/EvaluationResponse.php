<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'is_anonymous',
        'submitted_at',
        'consent_given',
        'consent_at',
        'consent_ip',
        'consent_user_agent',
        'consent_version',
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'submitted_at' => 'datetime',
            'consent_given' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EvaluationAnswer::class);
    }
}
