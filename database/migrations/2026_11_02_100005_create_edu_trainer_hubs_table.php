<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trainer eligibility matrix: which training centres a trainer can teach
 * in. topic_id = null is the default matrix for every topic; rows for a topic
 * override it. Uniqueness is kept by the code that writes the rows, because
 * SQLite lets several NULLs through a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_trainer_hubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainer_id')->constrained('edu_trainers')->cascadeOnDelete();
            $table->foreignId('hub_municipality_id')->constrained('edu_municipalities')->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('edu_topics')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['trainer_id', 'topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_trainer_hubs');
    }
};
