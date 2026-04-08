<?php

namespace Database\Seeders;

use App\Models\BookingStatus;
use Illuminate\Database\Seeder;

class BookingStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'status_key' => 'pending',
                'status_name' => 'قيد الانتظار',
                'description' => 'تم إرسال طلب الحجز وينتظر قبول السائق أو الإدارة.',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'status_key' => 'accepted',
                'status_name' => 'مقبول',
                'description' => 'تم قبول الحجز وتأكيد المقاعد.',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'status_key' => 'rejected',
                'status_name' => 'مرفوض',
                'description' => 'تم رفض الحجز مع توثيق السبب.',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ],
            [
                'status_key' => 'canceled',
                'status_name' => 'ملغى',
                'description' => 'تم إلغاء الحجز من الراكب أو الإدارة وفق سياسة الإلغاء.',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ],
            [
                'status_key' => 'completed',
                'status_name' => 'منتهي',
                'description' => 'تم تنفيذ الرحلة واكتمال الحجز بنجاح.',
                'is_final' => true,
                'display_order' => 5,
                'is_active' => true,
            ],
        ];

        foreach ($statuses as $status) {
            BookingStatus::updateOrCreate(
                ['status_key' => $status['status_key']],
                $status
            );
        }
    }
}
