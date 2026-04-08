<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_attendances', function (Blueprint $table) {
            $table->id('attendance_id');

            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings', 'booking_id')
                ->cascadeOnDelete();

            $table->foreignId('status_id')
                ->constrained('booking_attendance_statuses', 'status_id')
                ->restrictOnDelete();

            $table->foreignId('marked_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->timestamp('marked_at')->nullable();
            $table->decimal('penalty_amount', 10, 2)->default(0);
            $table->decimal('rating_penalty', 4, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_attendances');
    }
};
