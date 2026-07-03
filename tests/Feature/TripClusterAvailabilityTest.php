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
use App\Models\VehicleCategory;
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
            'start_governorate_id' => $start->governorate_id,
            'pickup_latitude' => 33.5139,
            'pickup_longitude' => 36.2766,
        ]))
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.from.address', 'Start')
            ->assertJsonPath('data.items.0.from.display_address', 'Start note')
            ->assertJsonPath('data.items.0.to.address', 'End')
            ->assertJsonPath('data.items.0.to.display_address', 'End note')
            ->assertJsonPath('data.items.0.driver.image', 'driver-photo.jpg');

        $this->getJson('/api/v1/passenger/trips/search?'.http_build_query([
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_date' => now()->toDateString(),
            'trip_type' => 'private',
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $privateTrip->trip_id);
    }

    public function test_passenger_search_with_pickup_and_dropoff_points_sorts_by_nearest_route_and_direction(): void
    {
        [$pending] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('point-search-driver@example.com', '0971111115');
        $passenger = $this->createPassenger('point-search-passenger@example.com', '0981111115');

        $nearestTrip = $this->createTrip(
            $driver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(4),
            false,
            true,
            33.5138,
            36.2765,
            34.7308,
            36.7090
        );

        $this->createTrip(
            $driver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(3),
            false,
            true,
            33.7000,
            36.6000,
            34.9000,
            36.9500
        );

        $reverseTrip = $this->createTrip(
            $driver,
            $pending->status_id,
            $end,
            $start,
            now()->addHours(2),
            false,
            true,
            34.7308,
            36.7090,
            33.5138,
            36.2765
        );

        Sanctum::actingAs($passenger);

        $response = $this->getJson('/api/v1/passenger/trips/search?'.http_build_query([
            'departure_date' => now()->toDateString(),
            'trip_type' => 'private',
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'pickup_latitude' => 33.5140,
            'pickup_longitude' => 36.2766,
            'dropoff_latitude' => 34.7309,
            'dropoff_longitude' => 36.7091,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.meta.search_mode', 'points')
            ->assertJsonPath('data.items.0.trip_id', $nearestTrip->trip_id);

        $this->assertNotContains(
            $reverseTrip->trip_id,
            collect($response->json('data.items'))->pluck('trip_id')->all()
        );
    }

    public function test_passenger_can_filter_trip_search_by_vehicle_category(): void
    {
        [$pending] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $taxiDriver = $this->createDriver('taxi-category-driver@example.com', '0971111121', 'تكسي صفراء');
        $vipDriver = $this->createDriver('vip-category-driver@example.com', '0971111122', 'قمة الرفاهية VIP');
        $passenger = $this->createPassenger('category-filter-passenger@example.com', '0981111121');

        $taxiTrip = $this->createTrip(
            $taxiDriver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(3),
            false,
            true,
            33.5138,
            36.2765,
            34.7308,
            36.7090
        );

        $vipTrip = $this->createTrip(
            $vipDriver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(2),
            false,
            true,
            33.5139,
            36.2766,
            34.7309,
            36.7091
        );

        $taxiCategoryId = VehicleCategory::where('name', 'تكسي صفراء')->value('category_id');

        Sanctum::actingAs($passenger);

        $response = $this->getJson('/api/v1/passenger/trips/search?'.http_build_query([
            'departure_date' => now()->toDateString(),
            'trip_type' => 'private',
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'pickup_latitude' => 33.5140,
            'pickup_longitude' => 36.2766,
            'dropoff_latitude' => 34.7309,
            'dropoff_longitude' => 36.7091,
            'vehicle_category_id' => $taxiCategoryId,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.trip_id', $taxiTrip->trip_id)
            ->assertJsonPath('data.items.0.vehicle.vehicle_category.category_id', $taxiCategoryId);

        $this->assertNotContains(
            $vipTrip->trip_id,
            collect($response->json('data.items'))->pluck('trip_id')->all()
        );
    }

    public function test_passenger_can_view_trip_details(): void
    {
        [$pending] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('details-driver@example.com', '0971111116');
        $passenger = $this->createPassenger('details-passenger@example.com', '0981111116');
        $bookedPassenger = $this->createPassenger('booked-passenger@example.com', '0981111117');

        $trip = $this->createTrip(
            $driver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(5),
            true,
            true
        );

        $acceptedStatus = BookingStatus::query()->where('status_key', 'accepted')->first();
        $rejectedStatus = BookingStatus::query()->where('status_key', 'rejected')->first();

        \App\Models\Booking::query()->create([
            'booking_code' => 'BK-DETAILS-1',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $bookedPassenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $acceptedStatus->status_id,
            'confirmed_at' => now(),
        ]);

        \App\Models\Booking::query()->create([
            'booking_code' => 'BK-DETAILS-2',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $rejectedStatus->status_id,
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson("/api/v1/passenger/trips/{$trip->trip_id}?trip_type=shared")
            ->assertOk()
            ->assertJsonPath('data.trip_id', $trip->trip_id)
            ->assertJsonPath('data.type.requested', 'shared')
            ->assertJsonPath('data.vehicle.type', 'Kia')
            ->assertJsonPath('data.vehicle.vehicle_category.name', 'تكسي صفراء')
            ->assertJsonPath('data.vehicle.vehicle_category.price_per_km', 84.5)
            ->assertJsonPath('data.vehicle.seat_capacity', 4)
            ->assertJsonPath('data.driver.image', 'driver-photo.jpg')
            ->assertJsonPath('data.route.from.display_address', 'Start note')
            ->assertJsonPath('data.route.to.display_address', 'End note')
            ->assertJsonPath('data.route.points.0.display_address', 'Start note')
            ->assertJsonPath('data.pricing.shared_price', 10000)
            ->assertJsonPath('data.pricing.private_price', 30000)
            ->assertJsonCount(1, 'data.passengers')
            ->assertJsonPath('data.actions.booking_endpoint', '/api/v1/passenger/bookings');

        $this->getJson("/api/v1/passenger/trips/{$trip->trip_id}?trip_type=private")
            ->assertOk()
            ->assertJsonPath('data.type.requested', 'private')
            ->assertJsonPath('data.pricing.display_price', 30000)
            ->assertJsonPath('data.available_seats', null)
            ->assertJsonCount(0, 'data.passengers');
    }

    public function test_passenger_can_list_destination_categories_and_category_trips(): void
    {
        [$pending] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('category-driver@example.com', '0971111118');
        $passenger = $this->createPassenger('category-passenger@example.com', '0981111118');

        $trip = $this->createTrip(
            $driver,
            $pending->status_id,
            $start,
            $end,
            now()->addHours(6),
            true,
            false
        );

        Sanctum::actingAs($passenger);

        $categoriesResponse = $this->getJson('/api/v1/passenger/trip-categories?'.http_build_query([
            'start_governorate_id' => $start->governorate_id,
            'trip_type' => 'shared',
            'departure_date' => now()->toDateString(),
        ]));

        $categoriesResponse
            ->assertOk()
            ->assertJsonFragment([
                'governorate_id' => $end->governorate_id,
                'image' => 'storage/governorates/homs.jpg',
                'available_trips_count' => 1,
            ]);

        $this->getJson("/api/v1/passenger/trip-categories/{$end->governorate_id}/trips?".http_build_query([
            'start_governorate_id' => $start->governorate_id,
            'trip_type' => 'shared',
            'departure_date' => now()->toDateString(),
            'pickup_latitude' => 33.5139,
            'pickup_longitude' => 36.2766,
            'dropoff_latitude' => 34.7309,
            'dropoff_longitude' => 36.7091,
        ]))
            ->assertOk()
            ->assertJsonPath('data.category.governorate_id', $end->governorate_id)
            ->assertJsonPath('data.items.0.trip_id', $trip->trip_id)
            ->assertJsonPath('data.items.0.to.governorate_id', $end->governorate_id)
            ->assertJsonPath('data.meta.start_governorate_id', $start->governorate_id)
            ->assertJsonPath('data.meta.search_mode', 'points')
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        [
                            'distance' => [
                                'pickup_km',
                                'dropoff_km',
                                'score_km',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_passenger_can_list_trip_history_sections_with_pickup_information(): void
    {
        [$pending, $active, $completed, , $canceled] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('passenger-sections-driver@example.com', '0971111119');
        $passenger = $this->createPassenger('passenger-sections@example.com', '0981111119');
        $acceptedStatus = BookingStatus::query()->where('status_key', 'accepted')->first();
        $canceledStatus = BookingStatus::query()->where('status_key', 'canceled')->first();

        $pendingTrip = $this->createTrip($driver, $pending->status_id, $start, $end, now()->addHours(3), true, false);
        $activeTrip = $this->createTrip($driver, $active->status_id, $start, $end, now()->subMinutes(10), true, false);
        $completedTrip = $this->createTrip($driver, $completed->status_id, $start, $end, now()->subHours(3), true, false);
        $canceledTrip = $this->createTrip($driver, $canceled->status_id, $start, $end, now()->addHours(5), true, false);

        $pendingBooking = $this->createPassengerBooking($pendingTrip, $passenger, $acceptedStatus->status_id, 'PENDING-BOOKING');
        $activeBooking = $this->createPassengerBooking($activeTrip, $passenger, $acceptedStatus->status_id, 'ACTIVE-BOOKING');
        $this->createPassengerBooking($completedTrip, $passenger, $acceptedStatus->status_id, 'COMPLETED-BOOKING');
        $this->createPassengerBooking($canceledTrip, $passenger, $canceledStatus->status_id, 'CANCELED-BOOKING');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/trips/current')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $activeTrip->trip_id)
            ->assertJsonPath('data.items.0.pickup.display_address', 'Passenger pickup')
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        [
                            'pickup' => ['meeting_time'],
                            'pricing' => ['display_price'],
                            'driver' => ['image'],
                            'vehicle' => ['image'],
                        ],
                    ],
                ],
            ]);

        $this->getJson('/api/v1/passenger/trips/pending')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $pendingTrip->trip_id);

        $this->getJson('/api/v1/passenger/trips/completed')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $completedTrip->trip_id);

        $this->getJson('/api/v1/passenger/trips/canceled')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $canceledTrip->trip_id);

        $this->getJson('/api/v1/passenger/bookings')
            ->assertOk()
            ->assertJsonPath('data.total_bookings', 4)
            ->assertJsonFragment([
                'booking_id' => $pendingBooking->booking_id,
                'details_endpoint' => "/api/v1/passenger/bookings/{$pendingBooking->booking_id}",
            ]);

        $this->getJson("/api/v1/passenger/trips/{$pendingTrip->trip_id}/bookings")
            ->assertOk()
            ->assertJsonPath('data.trip_id', $pendingTrip->trip_id)
            ->assertJsonPath('data.bookings.0.booking_id', $pendingBooking->booking_id)
            ->assertJsonMissingPath('data.trip_status')
            ->assertJsonMissingPath('data.driver')
            ->assertJsonMissingPath('data.vehicle');

        $this->getJson("/api/v1/passenger/bookings/{$activeBooking->booking_id}")
            ->assertOk()
            ->assertJsonPath('data.booking_id', $activeBooking->booking_id)
            ->assertJsonPath('data.pickup.display_address', 'Passenger pickup')
            ->assertJsonPath('data.trip.trip_id', $activeTrip->trip_id)
            ->assertJsonPath('data.actions.cancel_endpoint', "/api/v1/passenger/bookings/{$activeBooking->booking_id}/cancel");
    }

    private function seedTripStatuses(): array
    {
        return [
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::PENDING], [
                'status_name' => 'قيد الانتظار', 'description' => 'Pending', 'is_final' => false, 'display_order' => 1, 'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::ACTIVE], [
                'status_name' => 'نشطة', 'description' => 'Active', 'is_final' => false, 'display_order' => 2, 'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::COMPLETED], [
                'status_name' => 'مكتملة', 'description' => 'Completed', 'is_final' => true, 'display_order' => 3, 'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::AUTO_COMPLETED], [
                'status_name' => 'مكتملة تلقائياً', 'description' => 'Auto completed', 'is_final' => true, 'display_order' => 4, 'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::CANCELED], [
                'status_name' => 'ملغاة', 'description' => 'Canceled', 'is_final' => true, 'display_order' => 5, 'is_active' => true,
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
            Governorate::create(['name' => 'دمشق', 'image_url' => 'storage/governorates/damascus.jpg', 'is_active' => true, 'created_at' => now()]),
            Governorate::create(['name' => 'حمص', 'image_url' => 'storage/governorates/homs.jpg', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(string $email, string $phone, string $vehicleCategoryName = 'تكسي صفراء'): User
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
            'personal_photo' => 'driver-photo.jpg',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'vehicle_category_id' => VehicleCategory::where('name', $vehicleCategoryName)->value('category_id'),
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
            'note' => 'Start note',
            'sequence_order' => 1,
            'expected_arrival_time' => $departureTime,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => $endLat,
            'longitude' => $endLng,
            'address' => 'End',
            'note' => 'End note',
            'sequence_order' => 2,
            'expected_arrival_time' => Carbon::parse($departureTime)->addMinutes(90),
        ]);

        app(TripClusterService::class)->assignTripToCluster($trip->fresh(['points', 'status']));

        return $trip->fresh(['points', 'cluster']);
    }

    private function createPassengerBooking(Trip $trip, User $passenger, int $statusId, string $code): \App\Models\Booking
    {
        $booking = \App\Models\Booking::query()->create([
            'booking_code' => $code,
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $statusId,
            'confirmed_at' => now(),
        ]);

        $tripPoint = $trip->points()->orderBy('sequence_order')->first();

        \App\Models\BookingPickupPoint::query()->create([
            'booking_id' => $booking->booking_id,
            'trip_point_id' => $tripPoint->point_id,
            'governorate_id' => $trip->start_governorate_id,
            'point_name' => 'Passenger pickup',
            'address' => $tripPoint->address,
            'latitude' => $tripPoint->latitude,
            'longitude' => $tripPoint->longitude,
            'meeting_time' => $tripPoint->expected_arrival_time,
            'is_new' => false,
        ]);

        return $booking;
    }
}
