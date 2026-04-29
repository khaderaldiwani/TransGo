<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_clusters', function (Blueprint $table) {
            $table->id('cluster_id');
            $table->foreignId('reference_trip_id')
                ->nullable()
                ->constrained('trips', 'trip_id')
                ->nullOnDelete();
            $table->foreignId('start_governorate_id')
                ->constrained('governorates', 'governorate_id')
                ->restrictOnDelete();
            $table->foreignId('end_governorate_id')
                ->constrained('governorates', 'governorate_id')
                ->restrictOnDelete();
            $table->decimal('reference_start_latitude', 10, 7);
            $table->decimal('reference_start_longitude', 10, 7);
            $table->decimal('reference_end_latitude', 10, 7);
            $table->decimal('reference_end_longitude', 10, 7);
            $table->dateTime('reference_departure_time');
            $table->dateTime('time_window_start');
            $table->dateTime('time_window_end');
            $table->unsignedTinyInteger('open_trips_limit')->default(3);
            $table->timestamps();

            $table->index(['start_governorate_id', 'end_governorate_id']);
            $table->index(['time_window_start', 'time_window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_clusters');
    }
};
