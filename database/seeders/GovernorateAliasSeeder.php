<?php

namespace Database\Seeders;

use App\Models\Governorate;
use App\Models\GovernorateAlias;
use Illuminate\Database\Seeder;

class GovernorateAliasSeeder extends Seeder
{
    public function run(): void
    {
        $aliasesByGovernorate = [
            'دمشق' => [
                'دمشق',
                'محافظة دمشق',
                'Damascus',
                'Dimashq',
                'Damascus Governorate',
                'Muhafazat Dimashq',
            ],
            'ريف دمشق' => [
                'ريف دمشق',
                'محافظة ريف دمشق',
                'Rif Dimashq',
                'Rural Damascus',
                'Damascus Countryside',
                'Rif Dimashq Governorate',
                'Rural Damascus Governorate',
            ],
            'القنيطرة' => [
                'القنيطرة',
                'محافظة القنيطرة',
                'Quneitra',
                'Qunaitra',
                'Al Qunaytirah',
                'Quneitra Governorate',
            ],
            'درعا' => [
                'درعا',
                'محافظة درعا',
                'Daraa',
                'Dara',
                'Dara Governorate',
                'Daraa Governorate',
            ],
            'السويداء' => [
                'السويداء',
                'محافظة السويداء',
                'As Suwayda',
                'Suwayda',
                'Sweida',
                'As-Suwayda',
                'As Suwayda Governorate',
            ],
            'حمص' => [
                'حمص',
                'محافظة حمص',
                'Homs',
                'Hims',
                'Homs Governorate',
            ],
            'حماة' => [
                'حماة',
                'محافظة حماة',
                'Hama',
                'Hamah',
                'Hama Governorate',
            ],
            'طرطوس' => [
                'طرطوس',
                'محافظة طرطوس',
                'Tartus',
                'Tartous',
                'Tartus Governorate',
            ],
            'اللاذقية' => [
                'اللاذقية',
                'محافظة اللاذقية',
                'Latakia',
                'Lattakia',
                'Latakia Governorate',
            ],
            'إدلب' => [
                'إدلب',
                'ادلب',
                'محافظة إدلب',
                'Idlib',
                'Idlib Governorate',
            ],
            'حلب' => [
                'حلب',
                'محافظة حلب',
                'Aleppo',
                'Halab',
                'Aleppo Governorate',
            ],
            'الرقة' => [
                'الرقة',
                'محافظة الرقة',
                'Raqqa',
                'Ar Raqqah',
                'Raqqah',
                'Raqqa Governorate',
            ],
            'دير الزور' => [
                'دير الزور',
                'محافظة دير الزور',
                'Deir ez Zor',
                'Deir al Zur',
                'Dayr az Zawr',
                'Deir Ezzor',
                'Deir ez-Zor Governorate',
            ],
            'الحسكة' => [
                'الحسكة',
                'محافظة الحسكة',
                'Al Hasakah',
                'Hasakah',
                'Hasaka',
                'Hassakeh',
                'Al Hasakah Governorate',
            ],
        ];

        foreach ($aliasesByGovernorate as $governorateName => $aliases) {
            $governorate = Governorate::query()->where('name', $governorateName)->first();

            if (! $governorate) {
                continue;
            }

            foreach ($aliases as $alias) {
                GovernorateAlias::query()->updateOrCreate(
                    ['normalized_alias' => GovernorateAlias::normalize($alias)],
                    [
                        'governorate_id' => $governorate->governorate_id,
                        'alias' => $alias,
                    ]
                );
            }
        }
    }
}
