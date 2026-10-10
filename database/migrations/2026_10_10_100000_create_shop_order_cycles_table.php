<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per week of the weekly group-order model. The deadlines are
        // fixed when the cycle is created, so a later rule change never moves
        // the dates clinics were already told.
        Schema::create('shop_order_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // e.g. 2026-W42 (ISO week of the cutoff)
            $table->foreignId('shop_order_model_id')->constrained('shop_order_models')->cascadeOnDelete();

            $table->timestamp('opens_at');
            $table->timestamp('cutoff_at');        // Mon 12:00 — last moment to order
            $table->timestamp('discount_due_at');  // Tue 08:00 — group discount approved
            $table->timestamp('reminder_at');      // Tue 12:00 — reminder to unpaid clinics
            $table->timestamp('payment_due_at');   // Tue 14:00 — electronic payment deadline
            $table->date('delivery_from');         // Wed
            $table->date('delivery_to');           // + 5 working days

            // open → closed → payment_open → payment_closed
            $table->string('status', 20)->default('open');

            $table->decimal('extra_discount_percent', 5, 2)->nullable();
            $table->timestamp('discount_approved_at')->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('auto_approved')->default(false);

            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('payment_closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'cutoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_cycles');
    }
};
