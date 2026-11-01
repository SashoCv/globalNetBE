<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training topics, each with its own list of trainers (edu_topic_trainers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_topics', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->string('short_title', 150)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_topics');
    }
};
