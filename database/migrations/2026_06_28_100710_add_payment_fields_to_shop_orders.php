<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('pending')->after('status');
            $table->decimal('wallet_applied', 12, 2)->default(0)->after('total');
            $table->decimal('rebate_amount', 12, 2)->default(0)->after('wallet_applied');
            $table->timestamp('rebate_credited_at')->nullable()->after('rebate_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'wallet_applied', 'rebate_amount', 'rebate_credited_at']);
        });
    }
};
