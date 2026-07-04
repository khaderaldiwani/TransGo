<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReliabilityNfrApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_book_more_seats_than_available(): void
    {
        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger('reliable-passenger@example.com', '0993000001');
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'), $start, $end);
        $trip->update(['available_seats' => 1]);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/v1/passenger/bookings', $this->bookingPayload($trip, 2))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 1,
        ]);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_failed_wallet_payment_does_not_reduce_seats_or_balance(): void
    {
        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger('reliable-wallet@example.com', '0993000002', 5000);
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'), $start, $end);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/v1/passenger/bookings', [
            ...$this->bookingPayload($trip, 1),
            'payment_method' => 'electronic',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 4,
        ]);
        $this->assertSame('5000.00', $passenger->fresh('wallet')->wallet->balance);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_cancel_booking_restores_seat_and_wallet_refund(): void
    {
        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger('reliable-cancel@example.com', '0993000003', 50000);
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'), $start, $end);

        Sanctum::actingAs($passenger);

        $bookingId = $this->postJson('/api/v1/passenger/bookings', [
            ...$this->bookingPayload($trip, 1),
            'payment_method' => 'electronic',
        ])
            ->assertCreated()
            ->json('data.booking_id');

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 3,
        ]);

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 4,
        ]);
        $this->assertSame('50000.00', $passenger->fresh('wallet')->wallet->balance);
    }

    public function test_repeated_trip_completion_does_not_create_duplicate_settlement_receipts(): void
    {
        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver(50000);
        $passenger = $this->createPassenger('reliable-complete@example.com', '0993000004');
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::ACTIVE)->value('status_id'), $start, $end, now()->subHours(3));

        Booking::create([
            'booking_code' => 'REL-COMP',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => BookingStatus::where('status_key', 'accepted')->value('status_id'),
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/trips/{$trip->trip_id}/complete")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson("/api/v1/driver/trips/{$trip->trip_id}/complete")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Receipt::query()
            ->where('related_trip_id', $trip->trip_id)
            ->where('receipt_type', 'driver_trip_settlement')
            ->count());
    }

    public function test_success_booking_json_contract_keeps_current_shape(): void
    {
        $this->seedStatuses();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger('reliable-json@example.com', '0993000005');
        $trip = $this->createTrip($driver, TripStatus::where('status_key', TripStatus::PENDING)->value('status_id'), $start, $end);

        Sanctum::actingAs($passenger);

        $response = $this->postJson('/api/v1/passenger/bookings', $this->bookingPayload($trip, 1))
            ->assertCreated();

        $this->assertSame(
            ['success', 'message', 'data', 'status_code', 'timestamp'],
            array_keys($response->json())
        );
    }

    private function seedStatuses(): void
    {
        foreach ([
            [TripStatus::PENDING, 'Pending', false, 1],
            [TripStatus::ACTIVE, 'Active', false, 2],
            [TripStatus::COMPLETED, 'Completed', true, 3],
            [TripStatus::CANCELED, 'Canceled', true, 4],
        ] as [$key, $name, $isFinal, $order]) {
            TripStatus::create([
                'status_key' => $key,
                'status_name' => $name,
                'is_final' => $isFinal,
                'display_order' => $order,
                'is_active' => true,
            ]);
        }

        foreach ([
            ['pending', 'Pending', false, 1],
            ['accepted', 'Accepted', false, 2],
            ['rejected', 'Rejected', true, 3],
            ['canceled', 'Canceled', true, 4],
            ['completed', 'Completed', true, 5],
        ] as [$key, $name, $isFinal, $order]) {
            BookingStatus::create([
                'status_key' => $key,
                'status_name' => $name,
                'is_final' => $isFinal,
                'display_order' => $order,
                'is_active' => true,
            ]);
        }
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create(['name' => 'Start Gov', 'is_active' => true, 'created_at' => now()]),
            Governorate::create(['name' => 'End Gov', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(float $walletBalance = 0): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Reliability Driver',
            'phone' => '0983000001',
            'email' => 'reliability-driver@example.com',
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
            'car_type' => 'Kia',
            'seat_capacity' => 4,
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
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
            'full_name' => 'Reliability Passenger',
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
            'latitude' => 38.5000,
            'longitude' => -120.2000,
            'address' => 'Start',
            'sequence_order' => 1,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 43.2520,
            'longitude' => -126.4530,
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
