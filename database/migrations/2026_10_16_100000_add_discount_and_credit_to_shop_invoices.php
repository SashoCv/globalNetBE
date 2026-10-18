<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // What took the invoice below its full price, kept apart for the
    // accountant's price levelling (нивелација): the weekly group discount and
    // the clinic's points (wallet credit) used as a discount. Both gross, VAT
    // included, like every other amount on the invoice.
    public function up(): void
    {
        Schema::table('shop_invoices', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0)->after('surcharge_amount');
            $table->decimal('credit_amount', 12, 2)->default(0)->after('discount_amount');
        });

        // Existing invoices take theirs from the orders on them — the live
        // ones, or all of them for an invoice whose orders were all cancelled.
        foreach (DB::table('shop_invoices')->pluck('id') as $id) {
            $orders = DB::table('shop_orders')->where('shop_invoice_id', $id);
            $live = (clone $orders)->where('status', '!=', 'cancelled');
            $source = (clone $live)->exists() ? $live : $orders;

            DB::table('shop_invoices')->where('id', $id)->update([
                'discount_amount' => round((float) (clone $source)->sum('discount_amount'), 2),
                'credit_amount' => round((float) (clone $source)->sum('wallet_applied'), 2),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shop_invoices', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'credit_amount']);
        });
    }
};
