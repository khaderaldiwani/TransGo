<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TripSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $driver = User::whereHas('roles', fn ($query) => $query->where('name', Role::ROLE_DRIVER))->first();

        if (! $driver) {
            return;
        }

        $pendingStatus = TripStatus::where('status_key', TripStatus::PENDING)->first();

        if (! $pendingStatus) {
            return;
        }

        $trips = [
            [
                'departure_time' => now()->addDay()->setTime(9, 0, 0)->format('Y-m-d H:i:s'),
                'start_governorate_id' => 2,
                'end_governorate_id' => 5,
                'total_seats' => 4,
                'allow_shared' => true,
                'allow_private' => true,
                'shared_price' => 12.50,
                'private_price' => 45.00,
                'system_calculated_price' => 40.00,
                'estimated_distance_km' => 85.00,
                'estimated_duration_minutes' => 120,
                'route_polyline' => '',
                'status_id' => $pendingStatus->status_id,
            ],
            [
                'departure_time' => now()->addDay()->setTime(14, 30, 0)->format('Y-m-d H:i:s'),
                'start_governorate_id' => 3,
                'end_governorate_id' => 4,
                'total_seats' => 3,
                'allow_shared' => true,
                'allow_private' => false,
                'shared_price' => 10.00,
                'private_price' => null,
                'system_calculated_price' => 28.00,
                'estimated_distance_km' => 60.00,
                'estimated_duration_minutes' => 90,
                'route_polyline' => '',
                'status_id' => $pendingStatus->status_id,
            ],
        ];

        foreach ($trips as $index => $tripData) {
            $trip = Trip::updateOrCreate(
                [
                    'driver_id' => $driver->user_id,
                    'departure_time' => $tripData['departure_time'],
                ],
                array_merge($tripData, [
                    'available_seats' => $tripData['total_seats'],
                    'is_private_booked' => false,
                    'created_at' => now(),
                ])
            );

            $points = $this->pointsForTrip($index + 1, $trip);
            $trip->points()->delete();
            $trip->points()->createMany($points);
        }
    }

    private function pointsForTrip(int $tripNumber, Trip $trip): array
    {
        if ($tripNumber === 1) {
            return [
                [
                    'point_type' => 'start',
                    'latitude' => 33.5138,
                    'longitude' => 36.2765,
                    'address' => 'دمشق - منطقة البوابة',
                    'sequence_order' => 1,
                ],
                [
                    'point_type' => 'stop',
                    'latitude' => 33.5200,
                    'longitude' => 36.2900,
                    'address' => 'دمشق - نقطة توقف في المزة',
                    'sequence_order' => 2,
                ],
                [
                    'point_type' => 'end',
                    'latitude' => 36.2021,
                    'longitude' => 37.1611,
                    'address' => 'حلب - مركز المدينة',
                    'sequence_order' => 3,
                ],
            ];
        }

        return [
            [
                'point_type' => 'start',
                'latitude' => 33.5138,
                'longitude' => 36.2765,
                'address' => 'ريف دمشق - منطقة المصنع',
                'sequence_order' => 1,
            ],
            [
                'point_type' => 'stop',
                'latitude' => 34.7250,
                'longitude' => 36.7230,
                'address' => 'حماة - محطة الوقود',
                'sequence_order' => 2,
            ],
            [
                'point_type' => 'end',
                'latitude' => 35.1650,
                'longitude' => 36.8125,
                'address' => 'طرطوس - المنطقة البحرية',
                'sequence_order' => 3,
            ],
        ];
    }
}
