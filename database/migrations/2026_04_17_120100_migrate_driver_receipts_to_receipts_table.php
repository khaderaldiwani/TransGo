<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('receipts')) {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            try {
                $table->dropForeign(['related_receipt_id']);
            } catch (Throwable) {
                // Ignore missing foreign key to support fresh and existing databases.
            }
        });

        Schema::dropIfExists('driver_receipts');

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreign('related_receipt_id')
                ->references('receipt_id')
                ->on('receipts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            try {
                $table->dropForeign(['related_receipt_id']);
            } catch (Throwable) {
                // Ignore missing foreign key.
            }
        });
    }
};
