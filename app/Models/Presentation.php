<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Presentation extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_session_id',
        'title',
        'subtitle',
        'hero_image',
        'content',
        'cta_text',
        'cta_url',
        'gallery',
        'qr_token',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'gallery' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Presentation $presentation) {
            if (empty($presentation->qr_token)) {
                $presentation->qr_token = (string) Str::uuid();
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'event_session_id');
    }
}
