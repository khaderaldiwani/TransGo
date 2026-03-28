<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('full_name');
            $table->string('phone')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->tinyInteger('account_status')->default(1)->comment('0: inactive, 1: active');//, 2: suspended 
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->timestamp('rating_last_updated')->nullable();
            // Foreign key for created_by (self-referencing)
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users', 'user_id')
                  ->nullOnDelete();
                  
            $table->string('registration_type')->default('self')->comment('self / admin');
           
            $table->timestamps();

            //
            
        
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
