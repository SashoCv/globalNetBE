<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allocations: how many participants of a school (in their own language)
 * attend a group. A school can be split across groups and can sit in a
 * group of another language (Turkish participants in a Macedonian group,
 * printed "(1+1т)"). A row with 0 is an invited school without
 * participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_group_schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('edu_groups')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('edu_schools')->restrictOnDelete();
            $table->string('language', 2);
            $table->unsignedSmallInteger('participant_count')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['group_id', 'school_id', 'language']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_group_schools');
    }
};
