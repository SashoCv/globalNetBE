<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_order_models', function (Blueprint $table) {
            $table->decimal('surcharge_fixed_amount', 12, 2)->nullable()->after('surcharge_percent');
        });
    }

    public function down(): void
    {
        Schema::table('shop_order_models', function (Blueprint $table) {
            $table->dropColumn('surcharge_fixed_amount');
        });
    }
};
