<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_status_logs', function (Blueprint $table) {
            $table->id('log_id');

            $table->foreignId('complaint_id')
                ->constrained('complaints', 'complaint_id')
                ->cascadeOnDelete();

            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->text('notes')->nullable();

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_status_logs');
    }
};
