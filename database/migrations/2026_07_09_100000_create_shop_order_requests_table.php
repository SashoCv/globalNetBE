<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_order_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_clinic_id')->constrained('shop_clinics')->cascadeOnDelete();
            $table->foreignId('shop_order_id')->nullable()->constrained('shop_orders')->nullOnDelete();

            $table->enum('type', ['wrong_quantity', 'missing_item', 'product_request', 'other']);
            $table->string('subject')->nullable();
            $table->text('message');

            $table->enum('status', ['new', 'in_progress', 'resolved', 'rejected'])->default('new');
            $table->text('admin_note')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_requests');
    }
};
