<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_order_models', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();           // 'urgent' | 'monthly' | 'quarterly' | …
            $table->string('label')->nullable();        // "МОДЕЛ 01"
            $table->string('title');                    // "Итни нарачки"
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->json('bullets')->nullable();        // array of strings
            $table->string('icon', 64)->nullable();     // hero icon name
            $table->string('accent_color', 16)->nullable();
            $table->string('accent_color_soft', 16)->nullable();

            // Timing / scheduling config
            $table->unsignedSmallInteger('cutoff_day')->nullable();        // 1-31, day of month when orders stop being accepted for this cycle
            $table->unsignedTinyInteger('cutoff_hour')->nullable();        // 0-23, hour-of-day cutoff
            $table->unsignedSmallInteger('delivery_day')->nullable();      // 1-31, target day-of-month for delivery
            $table->unsignedSmallInteger('max_delivery_hours')->nullable();// for urgent: e.g. 48
            $table->unsignedSmallInteger('repeat_months')->default(0);     // 0=one-off, 1=monthly, 3=quarterly, ...
            $table->boolean('auto_repeat')->default(false);                // M03 — automatically re-issue

            // Common
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->decimal('surcharge_percent', 5, 2)->nullable();         // urgent surcharge (e.g. 10.00)
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_models');
    }
};
