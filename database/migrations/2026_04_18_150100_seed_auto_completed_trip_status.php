<?php

use App\Models\TripStatus;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        TripStatus::query()->updateOrCreate(
            ['status_key' => TripStatus::AUTO_COMPLETED],
            [
                'status_name' => 'منتهية تلقائياً',
                'description' => 'تم إنهاء الرحلة تلقائياً من قبل النظام.',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ]
        );

        TripStatus::query()
            ->where('status_key', TripStatus::CANCELED)
            ->update(['display_order' => 5]);
    }

    public function down(): void
    {
        TripStatus::query()
            ->where('status_key', TripStatus::AUTO_COMPLETED)
            ->delete();

        TripStatus::query()
            ->where('status_key', TripStatus::CANCELED)
            ->update(['display_order' => 4]);
    }
};
