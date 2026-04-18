<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('commission_rate_id')
                ->nullable()
                ->after('status_id')
                ->constrained('commission_rates', 'commission_rate_id')
                ->nullOnDelete();
            $table->decimal('commission_percentage', 5, 2)->default(0)->after('commission_rate_id');
            $table->decimal('max_commission_amount', 10, 2)->default(0)->after('commission_percentage');
            $table->decimal('gross_revenue_amount', 10, 2)->nullable()->after('max_commission_amount');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('gross_revenue_amount');
            $table->decimal('net_revenue_amount', 10, 2)->nullable()->after('commission_amount');
            $table->timestamp('completed_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_rate_id');
            $table->dropColumn([
                'commission_percentage',
                'max_commission_amount',
                'gross_revenue_amount',
                'commission_amount',
                'net_revenue_amount',
                'completed_at',
            ]);
        });
    }
};
