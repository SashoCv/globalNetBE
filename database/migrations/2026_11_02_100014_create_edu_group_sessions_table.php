<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The training days of a group (one row per day, times as 'H:i').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_group_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('edu_groups')->cascadeOnDelete();
            $table->date('date');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['group_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_group_sessions');
    }
};
