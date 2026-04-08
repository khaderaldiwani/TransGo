<?php

namespace Database\Seeders;

use App\Models\CommissionRate;
use Illuminate\Database\Seeder;

class CommissionRateSeeder extends Seeder
{
    public function run(): void
    {
        CommissionRate::updateOrCreate(
            [
                'percentage' => 10.00,
                'effective_from' => now()->startOfDay(),
            ],
            [
                'effective_to' => null,
                'is_active' => true,
                'created_by' => null,
            ]
        );
    }
}
