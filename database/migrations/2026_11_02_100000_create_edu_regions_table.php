<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обуки (teacher training programs): the regions municipalities are grouped
 * into (the 8 statistical regions, or the client's РЕОНИ sheet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_regions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_regions');
    }
};
