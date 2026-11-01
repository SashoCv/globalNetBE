<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schools taking part in a program with their participant counts per
 * language. count_total is kept equal to the sum by the model. In the
 * "persons" mode the counts are computed from edu_participants
 * (source = persons).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_program_schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('edu_programs')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('edu_schools')->restrictOnDelete();
            $table->unsignedSmallInteger('count_mk')->default(0);
            $table->unsignedSmallInteger('count_sq')->default(0);
            $table->unsignedSmallInteger('count_tr')->default(0);
            $table->unsignedSmallInteger('count_total')->default(0);
            $table->text('comment')->nullable();
            $table->string('source', 10)->default('manual'); // import | manual | persons
            $table->timestamps();

            $table->unique(['program_id', 'school_id']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_program_schools');
    }
};
