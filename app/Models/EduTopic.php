<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Обуки: a training topic with its own list of trainers.
 */
class EduTopic extends Model
{
    protected $fillable = ['title', 'short_title', 'description'];

    public function trainers(): BelongsToMany
    {
        return $this->belongsToMany(EduTrainer::class, 'edu_topic_trainers', 'topic_id', 'trainer_id');
    }
}
