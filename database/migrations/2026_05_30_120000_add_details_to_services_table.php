<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a flexible `details` JSON column to services. It holds the per-service
 * "references" section shown on the public service-detail page — events grouped
 * by year, rich training items (with images), or a client list — so the whole
 * page becomes editable from the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('details')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
