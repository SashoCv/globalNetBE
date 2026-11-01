<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обуки: a region municipalities are grouped into (8 statistical regions by
 * default, or the client's РЕОНИ sheet).
 */
class EduRegion extends Model
{
    protected $fillable = ['name', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function municipalities(): HasMany
    {
        return $this->hasMany(EduMunicipality::class, 'region_id');
    }
}
