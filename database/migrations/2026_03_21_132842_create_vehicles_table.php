<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('driver_id')->constrained('driver_profiles', 'user_id')->cascadeOnDelete();
    $table->string('car_type');
    $table->integer('seat_capacity')->default(4);
    $table->string('mechanical_car')->nullable();
    $table->string('insurance_image')->nullable();
    $table->string('ownership_document')->nullable();
    $table->string('certified_agency')->nullable();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
