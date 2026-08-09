<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->foreignId('shop_invoice_id')
                ->nullable()
                ->after('shop_order_model_id')
                ->constrained('shop_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_invoice_id');
        });
    }
};
