<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_reviews', function (Blueprint $table) {
            $table->string('rated_user_type', 20)->default('driver')->after('passenger_id');
            $table->boolean('is_visible')->default(true)->after('comment');
            $table->timestamp('hidden_at')->nullable()->after('is_visible');
            $table->foreignId('hidden_by')->nullable()->after('hidden_at')->constrained('users', 'user_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn([
                'rated_user_type',
                'is_visible',
                'hidden_at',
            ]);
        });
    }
};
