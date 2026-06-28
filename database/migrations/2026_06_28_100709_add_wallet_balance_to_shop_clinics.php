<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->decimal('wallet_balance', 12, 2)->default(0)->after('admin_note');
        });
    }

    public function down(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};
