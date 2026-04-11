<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_sessions', function (Blueprint $table) {
            $table->foreignId('event_session_type_id')
                ->nullable()
                ->after('event_id')
                ->constrained('event_session_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_session_type_id');
        });
    }
};
