<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_vendor_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_vendor_id')->constrained('shop_vendors')->cascadeOnDelete();
            $table->foreignId('shop_category_id')->constrained('shop_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shop_vendor_id', 'shop_category_id'], 'svc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_vendor_categories');
    }
};
