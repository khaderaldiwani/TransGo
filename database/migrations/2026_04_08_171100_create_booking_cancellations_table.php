<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_cancellations', function (Blueprint $table) {
            $table->id('cancellation_id');

            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings', 'booking_id')
                ->cascadeOnDelete();

            $table->foreignId('canceled_by')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->text('reason')->nullable();
            $table->timestamp('cancellation_time');
            $table->decimal('hours_before_departure', 8, 2)->nullable();
            $table->decimal('penalty_percentage', 5, 2)->default(0);
            $table->decimal('penalty_amount', 10, 2)->default(0);
            $table->decimal('wallet_refund_amount', 10, 2)->default(0);
            $table->decimal('rating_penalty', 4, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_cancellations');
    }
};
