<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_trainers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);          // Cyrillic
            $table->string('full_name_sq', 150)->nullable(); // Latin, printed for Albanian groups
            $table->string('name_key', 150)->index();
            $table->json('aliases')->nullable();
            // Languages the trainer teaches in (mk, sq, tr); empty means unknown.
            $table->json('languages');
            $table->foreignId('home_municipality_id')->nullable()->constrained('edu_municipalities')->nullOnDelete();
            $table->string('home_city_text', 100)->nullable();
            $table->string('education', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_trainers');
    }
};
