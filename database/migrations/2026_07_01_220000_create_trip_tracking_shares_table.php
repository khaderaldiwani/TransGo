<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_tracking_shares', function (Blueprint $table) {
            $table->id('share_id');
            $table->foreignId('trip_id')
                ->constrained('trips', 'trip_id')
                ->cascadeOnDelete();
            $table->foreignId('booking_id')
                ->constrained('bookings', 'booking_id')
                ->cascadeOnDelete();
            $table->foreignId('created_by')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();
            $table->string('token', 96)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'created_by'], 'trip_tracking_shares_trip_creator_idx');
            $table->index(['expires_at', 'revoked_at'], 'trip_tracking_shares_validity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_tracking_shares');
    }
};
