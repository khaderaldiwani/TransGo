<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_receipts', function (Blueprint $table) {
            $table->id('receipt_id');
            $table->string('receipt_number')->unique();

            $table->foreignId('trip_id')
                ->constrained('trips', 'trip_id')
                ->cascadeOnDelete();

            $table->foreignId('driver_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('commission_rate_id')
                ->nullable()
                ->constrained('commission_rates', 'commission_rate_id')
                ->nullOnDelete();

            $table->decimal('gross_amount', 12, 2);
            $table->decimal('commission_percentage', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('net_amount', 12, 2);
            $table->string('status'); // paid / processing
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_receipts');
    }
};
