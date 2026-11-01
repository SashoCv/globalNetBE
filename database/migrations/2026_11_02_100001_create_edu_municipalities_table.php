<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Municipalities are the unit trainings are grouped by. The 20 training
 * centres ("центри") are municipalities too: every municipality points at its
 * nearest centre, and a centre points at itself. "Град Скопје" is its own row
 * and is the Скопје centre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('name_sq', 100)->nullable();
            $table->foreignId('region_id')->constrained('edu_regions')->restrictOnDelete();
            $table->boolean('is_training_hub')->default(false);
            $table->foreignId('hub_municipality_id')->nullable()->constrained('edu_municipalities')->nullOnDelete();
            // Column header in the trainer matrix, e.g. "Куман.", "Гевгел.".
            $table->string('hub_short_name', 30)->nullable();
            $table->boolean('part_of_skopje')->default(false);
            // Other spellings seen in the client's sheets, e.g. "Тетово/ Tetovë".
            $table->json('aliases')->nullable();
            $table->timestamps();

            $table->index('region_id');
            $table->index('hub_municipality_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_municipalities');
    }
};
