<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id('transaction_id');

            $table->foreignId('wallet_id')
                ->constrained('wallets', 'wallet_id')
                ->cascadeOnDelete();

            $table->foreignId('related_booking_id')
                ->nullable()
                ->constrained('bookings', 'booking_id')
                ->nullOnDelete();

            $table->unsignedBigInteger('related_receipt_id')->nullable();

            $table->decimal('amount', 12, 2);
            $table->string('transaction_type'); // topup / debit / refund / commission / adjustment
            $table->string('status'); // completed / pending / failed
            $table->string('transaction_reference')->nullable();
            $table->string('description')->nullable();
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
