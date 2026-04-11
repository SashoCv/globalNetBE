<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSessionType extends Model
{
    protected $fillable = [
        'name',
        'color',
        'sort_order',
    ];

    public function sessions()
    {
        return $this->hasMany(EventSession::class);
    }
}
