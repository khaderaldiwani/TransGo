<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('receipts')) {
            return;
        }

        $this->dropWalletReceiptForeignIfExists();

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

        $this->dropWalletReceiptForeignIfExists();
    }

    private function dropWalletReceiptForeignIfExists(): void
    {
<<<<<<< HEAD
        if (DB::getDriverName() !== 'mysql') {
=======
        if (DB::getDriverName() === 'sqlite') {
>>>>>>> 4b04ec6c9863e4a1e1fc4ef72ccb5cb1e7dc0070
            return;
        }

        $databaseName = DB::getDatabaseName();

        if (! $databaseName) {
            return;
        }

        $constraint = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $databaseName)
            ->where('TABLE_NAME', 'wallet_transactions')
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->where('CONSTRAINT_NAME', 'wallet_transactions_related_receipt_id_foreign')
            ->exists();

        if (! $constraint) {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign('wallet_transactions_related_receipt_id_foreign');
        });
    }
};
