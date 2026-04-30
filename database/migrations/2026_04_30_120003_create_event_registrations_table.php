<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('event_kotizacija_id')->nullable()->constrained('event_kotizacii')->nullOnDelete();

            // Personal data
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone', 50);
            $table->string('city')->nullable();

            // Hotel
            $table->boolean('hotel_stay')->default(false);
            $table->string('hotel_name')->nullable();
            $table->string('hotel_room', 50)->nullable();
            $table->text('hotel_notes')->nullable();

            // Consent (GDPR/ZZLP audit)
            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->text('consent_user_agent')->nullable();
            $table->string('consent_version', 32)->nullable();

            $table->timestamp('registered_at')->useCurrent();
            $table->timestamps();

            // One person per email per event
            $table->unique(['event_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
