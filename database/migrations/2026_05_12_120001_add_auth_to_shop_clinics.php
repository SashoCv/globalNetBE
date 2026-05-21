<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('password');
            $table->string('remember_token', 100)->nullable()->after('email_verified_at');
            $table->string('password_reset_token', 100)->nullable()->after('remember_token');
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');
            $table->timestamp('last_login_at')->nullable()->after('password_reset_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_clinics', function (Blueprint $table) {
            $table->dropColumn([
                'password', 'email_verified_at', 'remember_token',
                'password_reset_token', 'password_reset_expires_at', 'last_login_at',
            ]);
        });
    }
};
