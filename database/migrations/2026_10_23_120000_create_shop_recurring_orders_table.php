<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-month orders (МОДЕЛ 04): the clinic orders once for a number of
 * months, and every billing period the same products are ordered again at a
 * price that can only go down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_recurring_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_clinic_id')->constrained('shop_clinics')->cascadeOnDelete();
            $table->foreignId('shop_order_model_id')->nullable()->constrained('shop_order_models')->nullOnDelete();
            $table->unsignedSmallInteger('months_total');
            // active → completed once every month is ordered, or cancelled by the admin.
            $table->string('status', 20)->default('active');
            $table->string('delivery_contact')->nullable();
            $table->string('delivery_phone', 50)->nullable();
            $table->string('delivery_email')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->text('delivery_notes')->nullable();
            // Clinics cannot cancel themselves; they ask and the admin decides.
            $table->timestamp('cancel_requested_at')->nullable();
            $table->text('cancel_request_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'shop_clinic_id']);
        });

        Schema::create('shop_recurring_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_recurring_order_id')->constrained('shop_recurring_orders')->cascadeOnDelete();
            $table->foreignId('shop_product_id')->constrained('shop_products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            // Sale price when the product was added; the clinic never pays more.
            $table->decimal('locked_price', 12, 2);
            $table->timestamps();

            $table->unique(['shop_recurring_order_id', 'shop_product_id'], 'recurring_item_product_unique');
        });

        Schema::table('shop_orders', function (Blueprint $table) {
            $table->foreignId('shop_recurring_order_id')->nullable()->after('shop_order_cycle_id')
                ->constrained('shop_recurring_orders')->nullOnDelete();
            $table->unsignedSmallInteger('recurring_month')->nullable()->after('shop_recurring_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_recurring_order_id');
            $table->dropColumn('recurring_month');
        });

        Schema::dropIfExists('shop_recurring_order_items');
        Schema::dropIfExists('shop_recurring_orders');
    }
};
