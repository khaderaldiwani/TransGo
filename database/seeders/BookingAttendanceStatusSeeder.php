<?php

namespace Database\Seeders;

use App\Models\BookingAttendanceStatus;
use Illuminate\Database\Seeder;

class BookingAttendanceStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'status_key' => 'not_checked',
                'status_name' => 'غير مسجل',
                'description' => 'لم يتم تحديد حالة حضور الراكب بعد.',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'status_key' => 'present',
                'status_name' => 'حاضر',
                'description' => 'تم تسجيل حضور الراكب عند نقطة الالتقاء.',
                'is_final' => true,
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'status_key' => 'absent',
                'status_name' => 'غائب',
                'description' => 'تم تسجيل غياب الراكب وتطبيق الإجراءات اللازمة.',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($statuses as $status) {
            BookingAttendanceStatus::updateOrCreate(
                ['status_key' => $status['status_key']],
                $status
            );
        }
    }
}
