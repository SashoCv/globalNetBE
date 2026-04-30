<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('qr_token')->nullable()->unique()->after('id');
            $table->boolean('registration_open')->default(true)->after('status');
        });

        // Backfill existing rows
        DB::table('events')->whereNull('qr_token')->orderBy('id')->each(function ($event) {
            DB::table('events')->where('id', $event->id)->update(['qr_token' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['qr_token', 'registration_open']);
        });
    }
};
