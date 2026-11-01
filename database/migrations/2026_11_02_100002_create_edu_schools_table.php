<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Primary and secondary schools, shared by every training program.
 *
 * Three forms of the name: display_name is the original (cleaned) text used in
 * outputs, name / name_sq are its Macedonian and Albanian halves, and name_key
 * is only for matching — it keeps the village, so two "Гоце Делчев" schools in
 * different villages of one municipality stay two schools.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_schools', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10); // primary | secondary
            $table->unsignedInteger('source_no')->nullable();
            $table->string('display_name', 255);
            $table->string('name', 255);
            $table->string('name_sq', 255)->nullable();
            $table->string('type_prefix', 20)->nullable();
            $table->string('settlement', 100)->nullable();
            $table->string('name_key', 255);
            $table->foreignId('municipality_id')->constrained('edu_municipalities')->restrictOnDelete();
            $table->text('address')->nullable();
            $table->text('address_sq')->nullable();
            // Teaching languages (mk, sq, tr); an empty list means unknown.
            $table->json('languages');
            $table->json('phones');
            $table->text('phone_raw')->nullable();
            $table->string('director_name')->nullable();
            $table->text('director_contact')->nullable();
            $table->json('emails');
            $table->text('email_raw')->nullable();
            $table->string('schools_mk_email')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['level', 'municipality_id', 'name_key']);
            $table->index(['level', 'municipality_id']);
            $table->index('name_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_schools');
    }
};
