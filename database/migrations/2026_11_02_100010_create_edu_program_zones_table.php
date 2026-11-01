<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grouping zones of one program. Created as one zone per region (region_id
 * is where it came from) and then merged, split or renamed per program
 * without touching the global regions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_program_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('edu_programs')->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('edu_regions')->nullOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['program_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_program_zones');
    }
};
