<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hc_clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->string('specialties');
            $table->string('phone');
            $table->string('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hc_clinics');
    }
};
