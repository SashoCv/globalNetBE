<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which zone a municipality belongs to in a program. language '*' is the
 * default for every language; 'mk' / 'sq' / 'tr' is an exception for that
 * language only (e.g. Albanian Долнени → Кичево, Macedonian Долнени → Прилеп).
 * Lookup: the row for the language first, then '*'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_program_zone_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('edu_programs')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('edu_program_zones')->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained('edu_municipalities')->cascadeOnDelete();
            $table->string('language', 3)->default('*');
            $table->timestamps();

            $table->unique(['program_id', 'municipality_id', 'language'], 'edu_pzm_program_municipality_language_unique');
            $table->index('zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_program_zone_municipalities');
    }
};
