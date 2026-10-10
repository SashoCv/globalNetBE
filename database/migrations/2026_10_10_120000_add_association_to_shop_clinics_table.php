<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            // The professional association (здружение) the clinic belongs to,
            // one of config('shop.associations').
            $table->string('association')->nullable()->after('edb');
        });
    }

    public function down(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->dropColumn('association');
        });
    }
};
