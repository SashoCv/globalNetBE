<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->foreignId('shop_order_cycle_id')->nullable()->after('shop_order_model_id')
                ->constrained('shop_order_cycles')->nullOnDelete();

            // Group discount taken off the price once the cycle's % is approved.
            $table->decimal('discount_percent', 5, 2)->default(0)->after('surcharge_amount');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent');

            $table->timestamp('payment_due_at')->nullable()->after('placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_order_cycle_id');
            $table->dropColumn(['discount_percent', 'discount_amount', 'payment_due_at']);
        });
    }
};
