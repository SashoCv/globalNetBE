<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();

            $table->foreignId('shop_clinic_id')->constrained('shop_clinics')->cascadeOnDelete();

            $table->date('period_from');
            $table->date('period_to');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('surcharge_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 8)->default('MKD');

            $table->enum('status', ['pending', 'paid', 'overdue', 'cancelled'])->default('pending');

            $table->timestamp('issued_at');
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method')->nullable();

            $table->text('admin_note')->nullable();

            $table->timestamps();

            $table->index(['shop_clinic_id', 'period_from', 'period_to']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_invoices');
    }
};
