<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->foreignId('shop_product_id')->nullable()->constrained('shop_products')->nullOnDelete();
            $table->foreignId('shop_vendor_id')->nullable()->constrained('shop_vendors')->nullOnDelete();

            // Snapshot at order time (so future product edits don't alter history)
            $table->string('product_name');
            $table->string('product_sku', 64)->nullable();
            $table->enum('kind', ['product', 'service'])->default('product');

            $table->decimal('unit_cost_price', 12, 2)->nullable();
            $table->decimal('unit_sale_price', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('subtotal', 12, 2);        // unit_sale_price × quantity
            $table->decimal('cost_subtotal', 12, 2);   // unit_cost_price × quantity

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_items');
    }
};
