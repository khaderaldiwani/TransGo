<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_attendance_statuses', function (Blueprint $table) {
            $table->id('status_id');
            $table->string('status_key')->unique();
            $table->string('status_name');
            $table->string('description')->nullable();
            $table->boolean('is_final')->default(false);
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_attendance_statuses');
    }
};
