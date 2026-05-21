<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('shop_clinic_id')->constrained('shop_clinics')->cascadeOnDelete();
            $table->foreignId('shop_order_model_id')->nullable()->constrained('shop_order_models')->nullOnDelete();

            // Status flow: new → confirmed → processing → in_delivery → completed
            //              (or cancelled at any point)
            $table->enum('status', ['new', 'confirmed', 'processing', 'in_delivery', 'completed', 'cancelled'])
                  ->default('new');

            // Money
            $table->decimal('subtotal', 12, 2)->default(0);             // sum of items (sale prices × qty)
            $table->decimal('cost_subtotal', 12, 2)->default(0);        // sum of items (cost prices × qty) — internal margin tracking
            $table->decimal('surcharge_amount', 12, 2)->default(0);     // urgent surcharge etc.
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 8)->default('MKD');

            // Delivery snapshot (so changes to clinic record don't alter past orders)
            $table->string('delivery_contact')->nullable();
            $table->string('delivery_phone', 50)->nullable();
            $table->string('delivery_email')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->text('delivery_notes')->nullable();

            // Timing
            $table->timestamp('placed_at')->useCurrent();
            $table->date('requested_delivery_date')->nullable();        // for monthly/quarterly: when next batch ships
            $table->date('next_recurrence_date')->nullable();           // for auto-repeat models
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('admin_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'placed_at']);
            $table->index(['shop_clinic_id', 'placed_at']);
            $table->index(['shop_order_model_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_orders');
    }
};
