<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Prices are VAT-inclusive; the rate splits them into base and VAT on the
    // invoice. Order items keep the rate they were sold at.
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(18)->after('cost_price');
        });

        Schema::table('shop_order_items', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('unit_sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_order_items', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
