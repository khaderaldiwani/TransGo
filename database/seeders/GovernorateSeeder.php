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
                    'image_url' => 'storage/governorates/'.$this->imageFileName($name),
                    'is_active' => true,
                    'created_at' => now(),
                ]
            );
        }
    }

    private function imageFileName(string $governorateName): string
    {
        return match ($governorateName) {
            'دمشق' => 'damascus.jpg',
            'ريف دمشق' => 'rif-dimashq.jpg',
            'حلب' => 'aleppo.jpg',
            'حمص' => 'homs.jpg',
            'حماة' => 'hama.jpg',
            'اللاذقية' => 'latakia.jpg',
            'طرطوس' => 'tartus.jpg',
            'إدلب' => 'idlib.jpg',
            'دير الزور' => 'deir-ez-zor.jpg',
            'الحسكة' => 'al-hasakah.jpg',
            'الرقة' => 'raqqa.jpg',
            'السويداء' => 'as-suwayda.jpg',
            'درعا' => 'daraa.jpg',
            'القنيطرة' => 'quneitra.jpg',
            default => 'default.jpg',
        };
    }
}
