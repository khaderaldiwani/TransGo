<?php

namespace Database\Seeders;

use App\Models\TripStatus;
use Illuminate\Database\Seeder;

class TripStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'status_key' => 'pending',
                'status_name' => 'قيد الانتظار',
                'description' => 'الرحلة بانتظار الانطلاق أو التأكيد.',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'status_key' => 'active',
                'status_name' => 'نشطة',
                'description' => 'الرحلة قيد التنفيذ حاليًا.',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'status_key' => 'completed',
                'status_name' => 'مكتملة',
                'description' => 'تم إنهاء الرحلة بنجاح.',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ],
            [
                'status_key' => 'canceled',
                'status_name' => 'ملغاة',
                'description' => 'تم إلغاء الرحلة.',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ],
        ];

        foreach ($statuses as $status) {
            TripStatus::updateOrCreate(
                ['status_key' => $status['status_key']],
                $status
            );
        }
    }
}
