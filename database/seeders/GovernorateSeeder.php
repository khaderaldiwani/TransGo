<?php

namespace Database\Seeders;

use App\Models\Governorate;
use Illuminate\Database\Seeder;

class GovernorateSeeder extends Seeder
{
    public function run(): void
    {
        $governorates = [
            'دير الزور',
            'دمشق',
            'ريف دمشق',
            'حماة',
            'حلب',
            'القنيطرة',
            'درعا',
            'السويداء',
            'حمص',
            'طرطوس',
            'اللاذقية',
            'إدلب',
            'الرقة',
            'الحسكة',
        ];

        foreach ($governorates as $name) {
            Governorate::updateOrCreate(
                ['name' => $name],
                [
                    'is_active' => true,
                    'created_at' => now(),
                ]
            );
        }
    }
}
