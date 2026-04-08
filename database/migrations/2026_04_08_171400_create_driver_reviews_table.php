<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_reviews', function (Blueprint $table) {
            $table->id('review_id');

            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings', 'booking_id')
                ->cascadeOnDelete();

            $table->foreignId('driver_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('passenger_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_reviews');
    }
};
