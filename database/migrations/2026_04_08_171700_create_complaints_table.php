<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id('complaint_id');
            $table->string('complaint_code')->unique();

            $table->foreignId('complainant_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->string('complainant_role'); // passenger / driver
            $table->string('complaint_type'); // trip / driver / passenger / payment / technical / system

            $table->foreignId('related_trip_id')
                ->nullable()
                ->constrained('trips', 'trip_id')
                ->nullOnDelete();

            $table->foreignId('related_booking_id')
                ->nullable()
                ->constrained('bookings', 'booking_id')
                ->nullOnDelete();

            $table->foreignId('related_driver_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->foreignId('related_passenger_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->text('description');
            $table->string('status')->default('new'); // new / in_progress / resolved
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
