<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->boolean('consent_given')->default(false)->after('is_anonymous');
            $table->timestamp('consent_at')->nullable()->after('consent_given');
            $table->string('consent_ip', 45)->nullable()->after('consent_at');
            $table->text('consent_user_agent')->nullable()->after('consent_ip');
            $table->string('consent_version', 32)->nullable()->after('consent_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->dropColumn([
                'consent_given',
                'consent_at',
                'consent_ip',
                'consent_user_agent',
                'consent_version',
            ]);
        });
    }
};
