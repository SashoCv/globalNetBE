<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training groups of a program, shown as "Група {number}". language is the
 * language the group is held in (participants keep their own language on
 * the allocations). start_date / end_date are copied from the sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('edu_programs')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->foreignId('zone_id')->nullable()->constrained('edu_program_zones')->nullOnDelete();
            $table->string('language', 2);
            $table->foreignId('location_id')->nullable()->constrained('edu_locations')->nullOnDelete();
            $table->string('location_text', 255)->nullable();
            $table->foreignId('venue_municipality_id')->nullable()->constrained('edu_municipalities')->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('edu_trainers')->nullOnDelete();
            $table->boolean('trainer_override')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft | planned | held | cancelled
            $table->boolean('is_locked')->default(false);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'number']);
            $table->index(['trainer_id', 'start_date']);
            $table->index('start_date');
            $table->index('zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_groups');
    }
};
