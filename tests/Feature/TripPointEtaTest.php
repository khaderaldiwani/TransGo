<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\GovernorateResolverService;
use App\Services\TripPreviewService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripPointEtaTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_expected_arrival_time_for_each_point_when_departure_time_is_sent(): void
    {
        $driver = $this->createDriver();
        [$damascus, $homs] = $this->createGovernorates();
        $this->fakeGovernorateResolver($damascus, $homs);

        $preview = app(TripPreviewService::class)->preview([
            'departure_time' => now()->addHour()->toIso8601String(),
            'total_seats' => 4,
            'allow_shared' => true,
            'allow_private' => true,
            'points' => [
                ['point_type' => 'start', 'latitude' => 33.5138, 'longitude' => 36.2765],
                ['point_type' => 'stop', 'latitude' => 34.0000, 'longitude' => 36.5000],
                ['point_type' => 'end', 'latitude' => 34.7308, 'longitude' => 36.7090],
            ],
        ], $driver);

        $this->assertNotNull($preview['ordered_points'][0]['expected_arrival_time']);
        $this->assertNotNull($preview['ordered_points'][1]['expected_arrival_time']);
        $this->assertNotNull($preview['ordered_points'][2]['expected_arrival_time']);
    }

    public function test_store_trip_persists_expected_arrival_time_on_trip_points(): void
    {
        $driver = $this->createDriver();
        $pendingStatus = TripStatus::create([
            'status_key' => TripStatus::PENDING,
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$damascus, $homs] = $this->createGovernorates();
        $this->fakeGovernorateResolver($damascus, $homs);

        $trip = app(TripService::class)->createTrip([
            'departure_time' => now()->addHour()->toIso8601String(),
            'total_seats' => 4,
            'allow_shared' => true,
            'allow_private' => true,
            'shared_price' => 1500,
            'private_price' => 6000,
            'points' => [
                ['point_type' => 'start', 'latitude' => 33.5138, 'longitude' => 36.2765],
                ['point_type' => 'stop', 'latitude' => 34.0000, 'longitude' => 36.5000],
                ['point_type' => 'end', 'latitude' => 34.7308, 'longitude' => 36.7090],
            ],
        ], $driver);

        $this->assertEquals($pendingStatus->status_id, $trip->status_id);
        $this->assertCount(3, $trip->points);
        $this->assertNotNull($trip->points[0]->expected_arrival_time);
        $this->assertNotNull($trip->points[1]->expected_arrival_time);
        $this->assertNotNull($trip->points[2]->expected_arrival_time);
    }

    private function createDriver(): User
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Eta Driver',
            'phone' => '0988888811',
            'email' => 'eta-driver@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($driverRole->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Toyota',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate-eta',
            'certified_agency' => '2023',
        ]);

        return $driver;
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create(['name' => 'دمشق', 'is_active' => true, 'created_at' => now()]),
            Governorate::create(['name' => 'حمص', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function fakeGovernorateResolver(Governorate $start, Governorate $end): void
    {
        $resolver = new class($start, $end) extends GovernorateResolverService {
            public function __construct(
                private Governorate $startGovernorate,
                private Governorate $endGovernorate
            ) {
            }

            public function enrichPointsWithAddresses(array $orderedPoints): array
            {
                $lastIndex = count($orderedPoints) - 1;

                return collect($orderedPoints)->map(function (array $point, int $index) use ($lastIndex) {
                    $point['address'] = $index === 0 ? 'دمشق' : ($index === $lastIndex ? 'حمص' : 'توقف');
                    return $point;
                })->all();
            }

            public function resolveTripGovernorates(array $orderedPoints): array
            {
                return [
                    'start_governorate_id' => (int) $this->startGovernorate->governorate_id,
                    'end_governorate_id' => (int) $this->endGovernorate->governorate_id,
                    'start_governorate' => $this->startGovernorate,
                    'end_governorate' => $this->endGovernorate,
                ];
            }
        };

        $this->app->instance(GovernorateResolverService::class, $resolver);
    }
}
