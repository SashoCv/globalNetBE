<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-image "show in hero" flag so the admin can pick exactly which gallery
 * images appear in the homepage hero collage (independent of show_on_home).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->boolean('show_on_hero')->default(false)->after('show_on_home');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->dropColumn('show_on_hero');
        });
    }
};
