<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-image "show on home" flag so the admin can pick exactly which gallery
 * images appear in the home "Од нашата галерија" preview (independent of the
 * event-level show_on_home).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->boolean('show_on_home')->default(false)->after('is_cover');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->dropColumn('show_on_home');
        });
    }
};
