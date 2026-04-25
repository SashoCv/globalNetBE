<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            // 'anonymous' = only anonymous allowed
            // 'identified' = only with name/email allowed
            // 'both' = user chooses
            $table->string('anonymity_mode', 20)->default('both')->after('description');
        });

        // Migrate existing data: allow_anonymous=true → both, false → identified
        DB::table('evaluations')->where('allow_anonymous', true)->update(['anonymity_mode' => 'both']);
        DB::table('evaluations')->where('allow_anonymous', false)->update(['anonymity_mode' => 'identified']);

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn('allow_anonymous');
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->boolean('allow_anonymous')->default(true);
        });

        DB::table('evaluations')->whereIn('anonymity_mode', ['anonymous', 'both'])->update(['allow_anonymous' => true]);
        DB::table('evaluations')->where('anonymity_mode', 'identified')->update(['allow_anonymous' => false]);

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn('anonymity_mode');
        });
    }
};
