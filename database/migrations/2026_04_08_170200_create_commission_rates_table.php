<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id('commission_rate_id');
            $table->decimal('percentage', 5, 2);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rates');
    }
};
