<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_events', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
            $table->string('location_en')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_events', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'location_en']);
        });
    }
};
