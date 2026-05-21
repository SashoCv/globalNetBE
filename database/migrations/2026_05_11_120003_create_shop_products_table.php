<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_vendor_id')->constrained('shop_vendors')->cascadeOnDelete();
            $table->foreignId('shop_category_id')->nullable()->constrained('shop_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('sku', 64)->nullable();
            $table->enum('kind', ['product', 'service'])->default('product');
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 8)->default('MKD');
            $table->integer('stock')->nullable(); // null = N/A (services)

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('image', 500)->nullable();

            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['shop_vendor_id', 'status']);
            $table->index(['shop_category_id', 'status']);
            $table->unique(['shop_vendor_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_products');
    }
};
