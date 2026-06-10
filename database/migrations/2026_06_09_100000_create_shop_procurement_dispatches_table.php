<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_procurement_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_vendor_id')->nullable()
                ->constrained('shop_vendors')->nullOnDelete();
            $table->string('vendor_name');
            $table->string('to_email')->nullable();
            $table->string('status')->default('sent'); // sent
            $table->string('scope')->default('active'); // active|new_confirmed|all_except_cancelled|all
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedInteger('total_quantity')->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->json('items')->nullable();   // snapshot of product lines sent
            $table->text('note')->nullable();
            $table->boolean('emailed')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['shop_vendor_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_procurement_dispatches');
    }
};
