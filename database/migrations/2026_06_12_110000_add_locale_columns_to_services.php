<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds English translation columns alongside the Macedonian base content.
 * Macedonian stays in the original columns; the public API overlays *_en when
 * the requested locale is English (falling back to mk when empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
            $table->text('description_en')->nullable()->after('description');
            $table->json('details_en')->nullable()->after('details');
        });

        Schema::table('service_bullets', function (Blueprint $table) {
            $table->text('text_en')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'description_en', 'details_en']);
        });

        Schema::table('service_bullets', function (Blueprint $table) {
            $table->dropColumn('text_en');
        });
    }
};
