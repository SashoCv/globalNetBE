<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_payments', function (Blueprint $table) {
            $table->id();

            // Sent to cPay as Details2 and echoed back in every notification —
            // this is how a cPay response is matched to a payment here.
            // Max 10 characters, so it stays numeric and short.
            $table->string('reference', 10)->unique();

            // What is being paid: a ShopOrder (urgent) or a ShopInvoice (proforma).
            $table->morphs('payable');

            $table->foreignId('shop_clinic_id')->constrained('shop_clinics')->cascadeOnDelete();

            // The amount actually charged, in whole denars (cPay only accepts
            // amounts whose last two digits are 00).
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MKD');

            // new → the redirect form was built
            // redirected → the customer was sent to cPay
            // paid / failed / cancelled / expired → final
            $table->string('status', 20)->default('new');

            $table->string('details1', 32);

            // cPay's own reference, present once the customer submits card data.
            $table->string('cpay_payment_ref', 32)->nullable();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('redirected_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('cpay_payment_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_payments');
    }
};
