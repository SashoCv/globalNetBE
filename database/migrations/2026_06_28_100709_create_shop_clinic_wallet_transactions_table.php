<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_clinic_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_clinic_id')->constrained('shop_clinics')->cascadeOnDelete();
            $table->foreignId('shop_order_id')->nullable()->constrained('shop_orders')->nullOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_clinic_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_clinic_wallet_transactions');
    }
};
