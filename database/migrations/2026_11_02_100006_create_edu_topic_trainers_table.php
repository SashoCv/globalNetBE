<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_topic_trainers', function (Blueprint $table) {
            $table->foreignId('topic_id')->constrained('edu_topics')->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('edu_trainers')->cascadeOnDelete();

            $table->primary(['topic_id', 'trainer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_topic_trainers');
    }
};
