<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a vendor provides delivery. Defaults to false (unknown / no delivery)
 * so existing vendors keep their current behavior until set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_vendors', function (Blueprint $table) {
            $table->boolean('has_delivery')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shop_vendors', function (Blueprint $table) {
            $table->dropColumn('has_delivery');
        });
    }
};
