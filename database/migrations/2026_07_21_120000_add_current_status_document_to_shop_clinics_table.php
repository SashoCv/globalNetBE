<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->string('current_status_document', 500)->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->dropColumn('current_status_document');
        });
    }
};
