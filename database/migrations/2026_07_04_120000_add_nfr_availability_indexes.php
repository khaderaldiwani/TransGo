<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['wallet_id', 'created_at'], 'nfr_wallet_transactions_wallet_created_idx');
            $table->index(['status', 'created_at'], 'nfr_wallet_transactions_status_created_idx');
            $table->index(['performed_by', 'created_at'], 'nfr_wallet_transactions_performer_created_idx');
            $table->index('related_booking_id', 'nfr_wallet_transactions_booking_idx');
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'nfr_complaints_status_created_idx');
            $table->index(['complainant_id', 'status', 'created_at'], 'nfr_complaints_complainant_status_created_idx');
            $table->index(['assigned_to', 'status'], 'nfr_complaints_assigned_status_idx');
            $table->index('related_trip_id', 'nfr_complaints_trip_idx');
            $table->index('related_booking_id', 'nfr_complaints_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex('nfr_complaints_status_created_idx');
            $table->dropIndex('nfr_complaints_complainant_status_created_idx');
            $table->dropIndex('nfr_complaints_assigned_status_idx');
            $table->dropIndex('nfr_complaints_trip_idx');
            $table->dropIndex('nfr_complaints_booking_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('nfr_wallet_transactions_wallet_created_idx');
            $table->dropIndex('nfr_wallet_transactions_status_created_idx');
            $table->dropIndex('nfr_wallet_transactions_performer_created_idx');
            $table->dropIndex('nfr_wallet_transactions_booking_idx');
        });
    }
};
