<?php

namespace Tests\Feature;

use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripCluster;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\Wallet;
use App\Services\TripClusterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripClusterAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_trips_are_clustered_and_only_three_are_visible(): void
    {
        [$pending] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('cluster-driver@example.com', '0971111111');

        $trips = [];
        for ($index = 0; $index < 4; $index++) {
            $trips[] = $this->createTrip(
                $driver,
                $pending->status_id,
                $start,
                $end,
                now()->addHours(4)->addMinutes($index * 5),
                true,
                false,
                33.5138 + ($index * 0.001),
                36.2765 + ($index * 0.001),
                34.7308 + ($index * 0.001),
                36.7090 + ($index * 0.001)
            );
        }

        $this->assertSame(1, TripCluster::query()->count());
        $this->assertSame(3, Trip::query()->where('is_booking_visible', true)->count());
        $this->assertFalse((bool) $trips[3]->fresh()->is_booking_visible);
    }

    public function test_private_only_trips_are_not_clustered(): void
    {
        [$pending] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('private-driver@example.com', '0971111112');

        $trip = $this->createTrip(
            $driver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(5),
            false,
            true
        );

        $this->assertNull($trip->fresh()->cluster_id);
        $this->assertTrue((bool) $trip->fresh()->is_booking_visible);
        $this->assertSame(0, TripCluster::query()->count());
    }

    public function test_full_visible_trip_opens_next_shared_trip_in_cluster(): void
    {
        [$pending] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('open-next-driver@example.com', '0971111113');
        $passenger = $this->createPassenger();

        $trips = [];
        for ($index = 0; $index < 4; $index++) {
            $trips[] = $this->createTrip(
                $driver,
                $pending->status_id,
                $start,
                $end,
                now()->addHours(6)->addMinutes($index * 5),
                true,
                false,
                33.5138 + ($index * 0.001),
                36.2765 + ($index * 0.001),
                34.7308 + ($index * 0.001),
                36.7090 + ($index * 0.001)
            );
        }

        Sanctum::actingAs($passenger);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trips[0]->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 4,
            'payment_method' => 'cash',
            'pickup_point' => [
                'trip_point_id' => $trips[0]->points()->first()->point_id,
            ],
        ])->assertCreated();

        $this->assertFalse((bool) $trips[0]->fresh()->is_booking_visible);
        $this->assertTrue((bool) $trips[3]->fresh()->is_booking_visible);
        $this->assertSame(3, Trip::query()->where('is_booking_visible', true)->count());
    }

    public function test_passenger_search_returns_visible_shared_trips_and_private_trips_independently(): void
    {
        [$pending] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('search-driver@example.com', '0971111114');
        $passenger = $this->createPassenger('search-passenger@example.com', '0981111114');

        for ($index = 0; $index < 4; $index++) {
            $this->createTrip(
                $driver,
                $pending->status_id,
                $start,
                $end,
                now()->addHours(7)->addMinutes($index * 5),
                true,
                false,
                33.5138 + ($index * 0.001),
                36.2765 + ($index * 0.001),
                34.7308 + ($index * 0.001),
                36.7090 + ($index * 0.001)
            );
        }

        $privateTrip = $this->createTrip(
            $driver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(8),
            false,
            true,
            33.5140,
            36.2766,
            34.7309,
            36.7091
        );

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/trips/search?'.http_build_query([
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_date' => now()->toDateString(),
            'trip_type' => 'shared',
            'seats_required' => 1,
            'latitude' => 33.5139,
            'longitude' => 36.2766,
        ]))
            ->assertOk()
            ->assertJsonCount(3, 'data.items');

        $this->getJson('/api/v1/passenger/trips/search?'.http_build_query([
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_date' => now()->toDateString(),
            'trip_type' => 'private',
            'latitude' => 33.5139,
            'longitude' => 36.2766,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $privateTrip->trip_id);
    }

    private function seedTripStatuses(): array
    {
        return [
            TripStatus::create([
                'status_key' => TripStatus::PENDING,
                'status_name' => 'قيد الانتظار',
                'description' => 'Pending',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => TripStatus::ACTIVE,
                'status_name' => 'نشطة',
                'description' => 'Active',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ]),
        ];
    }

    private function seedBookingStatuses(): void
    {
        BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        BookingStatus::create([
            'status_key' => 'canceled',
            'status_name' => 'ملغى',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 2,
            'is_active' => true,
        ]);

        BookingStatus::create([
            'status_key' => 'rejected',
            'status_name' => 'مرفوض',
            'description' => 'Rejected',
            'is_final' => true,
            'display_order' => 3,
            'is_active' => true,
        ]);
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create(['name' => 'دمشق', 'is_active' => true, 'created_at' => now()]),
            Governorate::create(['name' => 'حمص', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(string $email, string $phone): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Cluster Driver',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'rating' => 4.8,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($role->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Kia',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate',
            'certified_agency' => '2026',
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
        ]);

        Wallet::create([
            'user_id' => $driver->user_id,
            'balance' => 0,
        ]);

        return $driver;
    }

    private function createPassenger(string $email = 'cluster-passenger@example.com', string $phone = '0981111111'): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => 'Cluster Passenger',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $passenger->user_id,
            'balance' => 0,
        ]);

        return $passenger;
    }

    private function createTrip(
        User $driver,
        int $statusId,
        Governorate $start,
        Governorate $end,
        $departureTime,
        bool $allowShared,
        bool $allowPrivate,
        float $startLat = 33.5138,
        float $startLng = 36.2765,
        float $endLat = 34.7308,
        float $endLng = 36.7090
    ): Trip {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime,
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.5,
            'total_seats' => 4,
            'available_seats' => 4,
            'allow_shared' => $allowShared,
            'allow_private' => $allowPrivate,
            'is_private_booked' => false,
            'shared_price' => $allowShared ? 10000 : null,
            'private_price' => $allowPrivate ? 30000 : null,
            'system_calculated_price' => 22000,
            'route_polyline' => 'encoded',
            'status_id' => $statusId,
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => $startLat,
            'longitude' => $startLng,
            'address' => 'Start',
            'sequence_order' => 1,
            'expected_arrival_time' => $departureTime,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => $endLat,
            'longitude' => $endLng,
            'address' => 'End',
            'sequence_order' => 2,
            'expected_arrival_time' => Carbon::parse($departureTime)->addMinutes(90),
        ]);

        app(TripClusterService::class)->assignTripToCluster($trip->fresh(['points', 'status']));

        return $trip->fresh(['points', 'cluster']);
    }
}
