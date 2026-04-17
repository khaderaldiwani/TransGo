<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id('receipt_id');
            $table->string('receipt_number')->unique();

            $table->foreignId('owner_user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->nullable()
                ->constrained('wallets', 'wallet_id')
                ->nullOnDelete();

            $table->foreignId('related_wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions', 'transaction_id')
                ->nullOnDelete();

            $table->foreignId('related_payment_id')
                ->nullable()
                ->constrained('payments', 'payment_id')
                ->nullOnDelete();

            $table->foreignId('related_booking_id')
                ->nullable()
                ->constrained('bookings', 'booking_id')
                ->nullOnDelete();

            $table->foreignId('related_trip_id')
                ->nullable()
                ->constrained('trips', 'trip_id')
                ->nullOnDelete();

            $table->foreignId('commission_rate_id')
                ->nullable()
                ->constrained('commission_rates', 'commission_rate_id')
                ->nullOnDelete();

            $table->string('receipt_type');
            $table->string('direction')->nullable();
            $table->string('status');
            $table->decimal('amount', 12, 2);

            $table->foreignId('counterparty_user_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->string('counterparty_name')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('commission_percentage', 5, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
