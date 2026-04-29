<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            if (! Schema::hasColumn('trips', 'cluster_id')) {
                $table->foreignId('cluster_id')
                    ->nullable()
                    ->after('end_governorate_id')
                    ->constrained('trip_clusters', 'cluster_id')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('trips', 'is_booking_visible')) {
                $table->boolean('is_booking_visible')
                    ->default(true)
                    ->after('is_private_booked');
            }

            if (! Schema::hasColumn('trips', 'cluster_assigned_at')) {
                $table->dateTime('cluster_assigned_at')
                    ->nullable()
                    ->after('is_booking_visible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            if (Schema::hasColumn('trips', 'cluster_id')) {
                $table->dropConstrainedForeignId('cluster_id');
            }

            if (Schema::hasColumn('trips', 'cluster_assigned_at')) {
                $table->dropColumn('cluster_assigned_at');
            }

            if (Schema::hasColumn('trips', 'is_booking_visible')) {
                $table->dropColumn('is_booking_visible');
            }
        });
    }
};
