<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Places trainings are held: a school, a hotel or anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('kind', 10); // school | hotel | other
            $table->foreignId('school_id')->nullable()->constrained('edu_schools')->nullOnDelete();
            $table->foreignId('municipality_id')->constrained('edu_municipalities')->restrictOnDelete();
            $table->text('address')->nullable();
            $table->text('contact')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('municipality_id');
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_locations');
    }
};
