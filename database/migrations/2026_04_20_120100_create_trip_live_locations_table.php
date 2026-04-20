<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_live_locations', function (Blueprint $table) {
            $table->id('location_id');
            $table->foreignId('trip_id')
                ->constrained('trips', 'trip_id')
                ->cascadeOnDelete();
            $table->foreignId('driver_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('heading', 8, 2)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['trip_id', 'recorded_at'], 'trip_live_locations_trip_recorded_idx');
            $table->index(['driver_id', 'recorded_at'], 'trip_live_locations_driver_recorded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_live_locations');
    }
};
