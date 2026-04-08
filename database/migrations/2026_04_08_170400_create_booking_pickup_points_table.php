<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pickup_points', function (Blueprint $table) {
            $table->id('pickup_point_id');

            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings', 'booking_id')
                ->cascadeOnDelete();

            $table->foreignId('trip_point_id')
                ->nullable()
                ->constrained('trip_points', 'point_id')
                ->nullOnDelete();

            $table->foreignId('governorate_id')
                ->nullable()
                ->constrained('governorates', 'governorate_id')
                ->nullOnDelete();

            $table->string('point_name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('meeting_time')->nullable();
            $table->boolean('is_new')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_pickup_points');
    }
};
