<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_questions', function (Blueprint $table) {
            // For checkbox-type questions: how many options the user must / can select.
            // null = unbounded.
            $table->unsignedSmallInteger('min_selections')->nullable()->after('required');
            $table->unsignedSmallInteger('max_selections')->nullable()->after('min_selections');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_questions', function (Blueprint $table) {
            $table->dropColumn(['min_selections', 'max_selections']);
        });
    }
};
