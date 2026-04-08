<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id('trip_id');

            $table->foreignId('driver_id')
                ->constrained('driver_profiles', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('start_governorate_id')
                ->constrained('governorates', 'governorate_id')
                ->restrictOnDelete();

            $table->foreignId('end_governorate_id')
                ->constrained('governorates', 'governorate_id')
                ->restrictOnDelete();

            $table->timestamp('departure_time');

            $table->integer('estimated_duration_minutes')->nullable();
            $table->decimal('estimated_distance_km', 8, 2)->nullable();

            $table->integer('total_seats');
            $table->integer('available_seats');

            $table->boolean('allow_shared')->default(true);
            $table->boolean('allow_private')->default(false);
            $table->boolean('is_private_booked')->default(false);

            $table->decimal('shared_price', 10, 2)->nullable();
            $table->decimal('private_price', 10, 2)->nullable();
            $table->decimal('system_calculated_price', 10, 2)->nullable();

            $table->foreignId('status_id')
                ->constrained('trip_statuses', 'status_id')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
