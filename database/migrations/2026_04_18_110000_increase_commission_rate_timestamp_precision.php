<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE commission_rates MODIFY effective_from DATETIME(6) NOT NULL');
        DB::statement('ALTER TABLE commission_rates MODIFY effective_to DATETIME(6) NULL');

        $rates = DB::table('commission_rates')
            ->orderBy('effective_from')
            ->orderBy('commission_rate_id')
            ->get();

        for ($i = 0; $i < $rates->count() - 1; $i++) {
            $current = $rates[$i];
            $next = $rates[$i + 1];

            DB::table('commission_rates')
                ->where('commission_rate_id', $current->commission_rate_id)
                ->update([
                    'effective_to' => $next->effective_from,
                ]);
        }

        $lastRate = $rates->last();
        if ($lastRate) {
            DB::table('commission_rates')
                ->where('commission_rate_id', $lastRate->commission_rate_id)
                ->update([
                    'effective_to' => null,
                ]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE commission_rates MODIFY effective_from TIMESTAMP(6) NOT NULL');
        DB::statement('ALTER TABLE commission_rates MODIFY effective_to TIMESTAMP(6) NULL');
    }
};
