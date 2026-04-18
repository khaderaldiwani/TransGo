<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('completion_mode')->nullable()->after('actual_start_time');
            $table->string('completion_reason')->nullable()->after('completion_mode');
            $table->timestamp('tracking_stopped_at')->nullable()->after('completion_reason');
            $table->decimal('completion_latitude', 10, 7)->nullable()->after('tracking_stopped_at');
            $table->decimal('completion_longitude', 10, 7)->nullable()->after('completion_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn([
                'completion_mode',
                'completion_reason',
                'tracking_stopped_at',
                'completion_latitude',
                'completion_longitude',
            ]);
        });
    }
};
