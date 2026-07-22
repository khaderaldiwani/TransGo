<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->index(['related_trip_id', 'receipt_type', 'owner_user_id'], 'nfr_receipts_trip_type_owner_idx');
            $table->index(['related_booking_id', 'receipt_type', 'owner_user_id'], 'nfr_receipts_booking_type_owner_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['booking_id', 'payment_method', 'payment_status'], 'nfr_payments_booking_method_status_idx');
            $table->index('transaction_reference', 'nfr_payments_transaction_reference_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['transaction_reference', 'transaction_type'], 'nfr_wallet_transactions_reference_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('nfr_wallet_transactions_reference_type_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('nfr_payments_booking_method_status_idx');
            $table->dropIndex('nfr_payments_transaction_reference_idx');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex('nfr_receipts_trip_type_owner_idx');
            $table->dropIndex('nfr_receipts_booking_type_owner_idx');
        });
    }
};
