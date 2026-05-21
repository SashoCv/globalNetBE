<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            // Цена кон нас (што плаќаме на вендорот). `price` останува продажна цена.
            $table->decimal('cost_price', 12, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
