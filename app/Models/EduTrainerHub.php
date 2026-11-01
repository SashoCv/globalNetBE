<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обуки: one cell of the trainer matrix — the trainer can teach at this
 * training centre, for one topic or (topic_id null) for every topic.
 */
class EduTrainerHub extends Model
{
    protected $fillable = ['trainer_id', 'hub_municipality_id', 'topic_id'];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(EduTrainer::class, 'trainer_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(EduMunicipality::class, 'hub_municipality_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(EduTopic::class, 'topic_id');
    }
}
