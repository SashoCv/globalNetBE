<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_session_id')->constrained('event_sessions')->cascadeOnDelete();
            $table->foreignId('event_attendee_id')->constrained('event_attendees')->cascadeOnDelete();
            $table->timestamp('checked_in_at')->useCurrent();
            $table->unique(['event_session_id', 'event_attendee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendance');
    }
};
