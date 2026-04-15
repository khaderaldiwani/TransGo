<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_points', function (Blueprint $table) {
            if (! Schema::hasColumn('trip_points', 'note')) {
                $table->string('note')->nullable()->after('address');
            }

            if (! Schema::hasColumn('trip_points', 'expected_arrival_time')) {
                $table->timestamp('expected_arrival_time')->nullable()->after('sequence_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trip_points', function (Blueprint $table) {
            if (Schema::hasColumn('trip_points', 'expected_arrival_time')) {
                $table->dropColumn('expected_arrival_time');
            }

            if (Schema::hasColumn('trip_points', 'note')) {
                $table->dropColumn('note');
            }
        });
    }
};
