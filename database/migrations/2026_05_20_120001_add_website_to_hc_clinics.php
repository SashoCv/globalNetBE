<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hc_clinics', function (Blueprint $table) {
            $table->string('website')->nullable()->after('working_hours');
        });
    }

    public function down(): void
    {
        Schema::table('hc_clinics', function (Blueprint $table) {
            $table->dropColumn('website');
        });
    }
};
