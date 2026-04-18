<?php

namespace Database\Seeders;

use App\Models\CommissionRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CommissionRateSeeder extends Seeder
{
    public function run(): void
    {
        if (CommissionRate::query()->exists()) {
            return;
        }

        CommissionRate::create([
            'percentage' => 5.00,
            'previous_percentage' => null,
            'effective_from' => Carbon::now()->subDay(),
            'effective_to' => null,
            'is_active' => true,
            'change_reason' => 'Default system commission rate.',
            'created_by' => null,
        ]);
    }
}
