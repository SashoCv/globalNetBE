<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('locale', 5)->default('mk')->after('group');
        });

        // Existing rows are Macedonian.
        \App\Models\Setting::query()->update(['locale' => 'mk']);

        // A key can now exist once per locale instead of being globally unique.
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_key_unique');
            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['key', 'locale']);
            $table->unique('key');
            $table->dropColumn('locale');
        });
    }
};
