<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participants of a program (the "persons" mode). The e-mail is not unique:
 * a school's shared address is often given for several people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('edu_programs')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('edu_schools')->nullOnDelete();
            $table->string('school_text', 255)->nullable();
            $table->foreignId('municipality_id')->nullable()->constrained('edu_municipalities')->nullOnDelete();
            $table->string('language', 2);
            $table->string('full_name', 150);
            $table->string('name_key', 150);
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('position', 100)->nullable();
            $table->json('extra')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('edu_groups')->nullOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('edu_imports')->nullOnDelete();
            $table->timestamps();

            $table->index(['program_id', 'email']);
            $table->index(['program_id', 'school_id']);
            $table->index(['program_id', 'group_id']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_participants');
    }
};
