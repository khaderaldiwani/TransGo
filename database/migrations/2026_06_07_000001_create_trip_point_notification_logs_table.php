<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_point_notification_logs', function (Blueprint $table) {
            $table->id('trip_point_notification_log_id');

            $table->foreignId('trip_id')
                ->constrained('trips', 'trip_id')
                ->cascadeOnDelete();

            $table->foreignId('point_id')
                ->constrained('trip_points', 'point_id')
                ->cascadeOnDelete();

            $table->string('notification_type');
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->unique(['trip_id', 'point_id', 'notification_type'], 'trip_point_notification_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_point_notification_logs');
    }
};
