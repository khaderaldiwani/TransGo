<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->index(['status_id', 'departure_time'], 'nfr_trips_status_departure_idx');
            $table->index(['driver_id', 'departure_time'], 'nfr_trips_driver_departure_idx');
            $table->index(['start_governorate_id', 'end_governorate_id', 'departure_time'], 'nfr_trips_route_departure_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['trip_id', 'created_at'], 'nfr_bookings_trip_created_idx');
            $table->index(['passenger_id', 'status_id', 'created_at'], 'nfr_bookings_passenger_status_created_idx');
            $table->index(['status_id', 'created_at'], 'nfr_bookings_status_created_idx');
        });

        Schema::table('trip_live_locations', function (Blueprint $table) {
            $table->index(['trip_id', 'recorded_at'], 'nfr_trip_live_locations_trip_recorded_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['reference_type', 'reference_id', 'created_at'], 'nfr_notifications_reference_created_idx');
            $table->index('created_at', 'nfr_notifications_created_idx');
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read', 'is_sent'], 'nfr_user_notifications_user_read_sent_idx');
            $table->index(['user_id', 'created_at'], 'nfr_user_notifications_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('nfr_user_notifications_user_read_sent_idx');
            $table->dropIndex('nfr_user_notifications_user_created_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('nfr_notifications_reference_created_idx');
            $table->dropIndex('nfr_notifications_created_idx');
        });

        Schema::table('trip_live_locations', function (Blueprint $table) {
            $table->dropIndex('nfr_trip_live_locations_trip_recorded_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('nfr_bookings_trip_created_idx');
            $table->dropIndex('nfr_bookings_passenger_status_created_idx');
            $table->dropIndex('nfr_bookings_status_created_idx');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex('nfr_trips_status_departure_idx');
            $table->dropIndex('nfr_trips_driver_departure_idx');
            $table->dropIndex('nfr_trips_route_departure_idx');
        });
    }
};
