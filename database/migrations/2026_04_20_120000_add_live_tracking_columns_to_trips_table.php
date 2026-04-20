<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('is_tracking_active')->default(false)->after('actual_start_time');
            $table->timestamp('tracking_started_at')->nullable()->after('is_tracking_active');
            $table->decimal('last_latitude', 10, 7)->nullable()->after('tracking_stopped_at');
            $table->decimal('last_longitude', 10, 7)->nullable()->after('last_latitude');
            $table->decimal('last_speed_kmh', 8, 2)->nullable()->after('last_longitude');
            $table->decimal('last_heading', 8, 2)->nullable()->after('last_speed_kmh');
            $table->decimal('last_accuracy_meters', 8, 2)->nullable()->after('last_heading');
            $table->timestamp('last_location_at')->nullable()->after('last_accuracy_meters');

            $table->index(['status_id', 'is_tracking_active'], 'trips_status_tracking_idx');
            $table->index('last_location_at', 'trips_last_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex('trips_status_tracking_idx');
            $table->dropIndex('trips_last_location_idx');
            $table->dropColumn([
                'is_tracking_active',
                'tracking_started_at',
                'last_latitude',
                'last_longitude',
                'last_speed_kmh',
                'last_heading',
                'last_accuracy_meters',
                'last_location_at',
            ]);
        });
    }
};
