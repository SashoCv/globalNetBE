<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery categories — the previously hardcoded "events"/"promotions" tabs are
 * now a managed list. Gallery events reference a category by its `slug` (the
 * existing GalleryEvent.category column already stores those slugs), so the slug
 * is stable/immutable once created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');          // Macedonian label
            $table->string('name_en')->nullable(); // English label (falls back to name)
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_categories');
    }
};
