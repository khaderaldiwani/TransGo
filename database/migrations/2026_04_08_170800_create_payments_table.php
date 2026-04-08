<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id('payment_id');

            $table->foreignId('booking_id')
                ->constrained('bookings', 'booking_id')
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->nullable()
                ->constrained('wallets', 'wallet_id')
                ->nullOnDelete();

            $table->string('payment_method'); // wallet / cash
            $table->decimal('amount', 10, 2);
            $table->string('payment_status'); // pending / paid / failed / refunded
            $table->string('transaction_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
