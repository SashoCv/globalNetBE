<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity-rebate tiers ("скали") for a product's cost-to-us. Each row is a
 * threshold: from `min_quantity` units upward, the per-unit cost is `unit_cost`.
 * The upper bound of a tier is implied by the next tier's min_quantity. The
 * effective cost depends on the AGGREGATED quantity across all clinics
 * (resolved at procurement time). Products without tiers fall back to cost_price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_cost_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained('shop_products')->cascadeOnDelete();
            $table->unsignedInteger('min_quantity')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();

            $table->index(['shop_product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_cost_tiers');
    }
};
