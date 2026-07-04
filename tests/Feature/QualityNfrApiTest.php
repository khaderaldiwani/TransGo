<?php

namespace Tests\Feature;

use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Events\TripLocationUpdated;
use App\Events\TripStatusChanged;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QualityNfrApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_creation_updates_capacity_dispatches_event_and_keeps_json_shape(): void
    {
        Event::fake([BookingCreated::class]);

        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger('quality-book@example.com', '0995000001');
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'), $start, $end);

        Sanctum::actingAs($passenger);

        $response = $this->postJson('/api/v1/passenger/bookings', $this->bookingPayload($trip, 2))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(['success', 'message', 'data', 'status_code', 'timestamp'], array_keys($response->json()));
        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 2,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'booking_confirmed_passenger',
            'reference_type' => Booking::class,
            'created_by' => $passenger->user_id,
        ]);
        Event::assertDispatched(BookingCreated::class);
    }

    public function test_cancel_booking_dispatches_status_event_and_restores_capacity(): void
    {
        Event::fake([BookingStatusChanged::class]);

        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger('quality-cancel@example.com', '0995000002');
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'), $start, $end);

        Sanctum::actingAs($passenger);

        $bookingId = $this->postJson('/api/v1/passenger/bookings', $this->bookingPayload($trip, 1))
            ->assertCreated()
            ->json('data.booking_id');

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 4,
        ]);
        Event::assertDispatched(BookingStatusChanged::class, fn (BookingStatusChanged $event) =>
            (int) $event->booking->booking_id === (int) $bookingId
        );
    }

    public function test_trip_start_dispatches_status_event_and_keeps_json_shape(): void
    {
        Event::fake([TripStatusChanged::class]);

        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $trip = $this->createTrip(
            $driver,
            TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'),
            $start,
            $end,
            now()->subMinutes(5)
        );

        Sanctum::actingAs($driver);

        $response = $this->postJson("/api/v1/driver/trips/{$trip->trip_id}/start")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(['success', 'message', 'data', 'status_code', 'timestamp'], array_keys($response->json()));
        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => TripStatus::where('status_key', TripStatus::ACTIVE)->value('status_id'),
            'is_tracking_active' => true,
        ]);
        Event::assertDispatched(TripStatusChanged::class, fn (TripStatusChanged $event) =>
            (int) $event->trip->trip_id === (int) $trip->trip_id
        );
    }

    public function test_location_update_dispatches_event_without_changing_tracking_json_shape(): void
    {
        Event::fake([TripLocationUpdated::class]);

        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $trip = $this->createTrip(
            $driver,
            TripStatus::where('status_key', TripStatus::ACTIVE)->value('status_id'),
            $start,
            $end,
            now()->subHour()
        );
        $trip->forceFill([
            'is_tracking_active' => true,
            'tracking_started_at' => now()->subMinutes(30),
        ])->save();

        Sanctum::actingAs($driver);

        $response = $this->postJson("/api/v1/driver/trips/{$trip->trip_id}/location", [
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'speed_kmh' => 35,
            'heading' => 120,
            'accuracy_meters' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(['success', 'message', 'data', 'status_code', 'timestamp'], array_keys($response->json()));
        $this->assertDatabaseHas('trip_live_locations', [
            'trip_id' => $trip->trip_id,
            'driver_id' => $driver->user_id,
        ]);
        Event::assertDispatched(TripLocationUpdated::class);
    }

    private function seedStatuses(): void
    {
        foreach ([
            [TripStatus::PENDING, 'Pending', false, 1],
            [TripStatus::ACTIVE, 'Active', false, 2],
            [TripStatus::COMPLETED, 'Completed', true, 3],
            [TripStatus::CANCELED, 'Canceled', true, 4],
            [TripStatus::AUTO_COMPLETED, 'Auto completed', true, 5],
        ] as [$key, $name, $isFinal, $order]) {
            TripStatus::updateOrCreate(
                ['status_key' => $key],
                [
                    'status_name' => $name,
                    'is_final' => $isFinal,
                    'display_order' => $order,
                    'is_active' => true,
                ]
            );
        }

        foreach ([
            ['pending', 'Pending', false, 1],
            ['accepted', 'Accepted', false, 2],
            ['rejected', 'Rejected', true, 3],
            ['canceled', 'Canceled', true, 4],
            ['completed', 'Completed', true, 5],
        ] as [$key, $name, $isFinal, $order]) {
            BookingStatus::updateOrCreate(
                ['status_key' => $key],
                [
                    'status_name' => $name,
                    'is_final' => $isFinal,
                    'display_order' => $order,
                    'is_active' => true,
                ]
            );
        }
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create(['name' => 'Quality Start', 'is_active' => true, 'created_at' => now()]),
            Governorate::create(['name' => 'Quality End', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(float $walletBalance = 50000): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Quality Driver',
            'phone' => '0985000001',
            'email' => 'quality-driver@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $driver->roles()->attach($role->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Toyota',
            'seat_capacity' => 4,
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'quality-vehicle.jpg',
        ]);

        Wallet::create([
            'user_id' => $driver->user_id,
            'balance' => $walletBalance,
        ]);

        return $driver->fresh('wallet');
    }

    private function createPassenger(string $email, string $phone, float $walletBalance = 0): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => 'Quality Passenger',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);
        $passenger->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $passenger->user_id,
            'balance' => $walletBalance,
        ]);

        return $passenger->fresh('wallet');
    }

    private function createTrip(User $driver, int $statusId, Governorate $start, Governorate $end, mixed $departureTime = null): Trip
    {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime ?? now()->addHours(4),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.5,
            'total_seats' => 4,
            'available_seats' => 4,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 30000,
            'system_calculated_price' => 22000,
            'commission_percentage' => 10,
            'max_commission_amount' => 10000,
            'status_id' => $statusId,
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Start',
            'sequence_order' => 1,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 34.7308,
            'longitude' => 36.7090,
            'address' => 'End',
            'sequence_order' => 2,
        ]);

        return $trip;
    }

    private function bookingPayload(Trip $trip, int $seats): array
    {
        return [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => $seats,
            'payment_method' => 'cash',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->where('point_type', 'start')->value('point_id'),
            ],
        ];
    }
}
