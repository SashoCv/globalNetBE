<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training programs (projects): the root everything else hangs off —
 * per-school counts, participants, zones, groups and outputs.
 *
 * Enum-like values are plain strings (constants on EduProgram), times are
 * 'H:i' strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_programs', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->foreignId('topic_id')->nullable()->constrained('edu_topics')->nullOnDelete();
            $table->string('school_level', 10);              // primary | secondary
            $table->string('staff_type', 30);                // teachers | career_counselors | professional_associates
            $table->unsignedSmallInteger('group_size_min');
            $table->unsignedSmallInteger('group_size_max');
            $table->string('trainer_assignment_mode', 10)->default('auto'); // auto | manual
            $table->string('participant_mode', 10)->default('counts');      // counts | persons
            // Participant language => language the group is formed in, e.g. {"mk":"mk","sq":"sq","tr":"mk"}.
            $table->json('language_map');
            $table->string('period_label', 30)->nullable();
            $table->string('default_start_time', 5)->nullable(); // '08:30'
            $table->string('default_end_time', 5)->nullable();   // '13:30'
            $table->unsignedTinyInteger('default_days')->default(2);
            $table->string('status', 10)->default('draft');      // draft | final
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_programs');
    }
};
