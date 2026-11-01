<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upload → preview → resolve → apply log for Excel imports (the master-data
 * workbook now; participant lists and per-school counts once programs exist).
 *
 * program_id has no foreign key yet: edu_programs is created by a later
 * migration, and adding a key afterwards would rebuild the table in SQLite.
 * Deleting a program removes its imports in the EduProgram model instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->nullable()->index();
            $table->string('type', 20);   // workbook | participants | school_counts
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();          // local disk, edu-imports/…
            $table->string('status', 20); // previewed | applied | failed | discarded
            $table->json('summary')->nullable();
            $table->string('preview_path')->nullable();
            $table->json('options')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_imports');
    }
};
