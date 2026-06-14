<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product GNA margin and delivery add-on. These feed the "suggested sale
 * price" helper in admin; the clinic-facing `price` stays manually set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->decimal('margin_percent', 5, 2)->nullable()->after('cost_price');
            $table->decimal('delivery_fee', 12, 2)->nullable()->after('margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn(['margin_percent', 'delivery_fee']);
        });
    }
};
