<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_attendance', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendance', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
