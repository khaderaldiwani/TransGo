<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_points', function (Blueprint $table) {
            $table->id('point_id');

            $table->foreignId('trip_id')
                ->constrained('trips', 'trip_id')
                ->cascadeOnDelete();

            $table->string('point_type'); // start / stop / end
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address')->nullable();
            $table->integer('sequence_order');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_points');
    }
};
