<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id('booking_id');
            $table->string('booking_code')->unique();

            $table->foreignId('trip_id')
                ->constrained('trips', 'trip_id')
                ->cascadeOnDelete();

            $table->foreignId('passenger_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->string('booking_type'); // shared / private
            $table->unsignedTinyInteger('seats_reserved');
            $table->string('payment_method'); // wallet / cash
            $table->decimal('total_amount', 10, 2);

            $table->foreignId('status_id')
                ->constrained('booking_statuses', 'status_id')
                ->restrictOnDelete();

            $table->foreignId('attendance_status_id')
                ->nullable()
                ->constrained('booking_attendance_statuses', 'status_id')
                ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'status_id']);
            $table->index(['passenger_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
