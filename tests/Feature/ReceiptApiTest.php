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

class ReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_list_and_view_wallet_topup_receipt(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin Receipts', 'admin-receipts@example.com');
        $passenger = $this->createPassenger('Passenger Receipts', 'passenger-receipts@example.com', '0985000001');

        Sanctum::actingAs($admin);

        $topUpResponse = $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 100,
            'reason' => 'Recharge for receipt test',
        ])->assertOk();

        $receiptId = $topUpResponse->json('data.receipt.receipt_id');
        $receiptNumber = $topUpResponse->json('data.receipt.receipt_number');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/receipts')
            ->assertOk()
            ->assertJsonPath('data.data.0.receipt_id', $receiptId)
            ->assertJsonPath('data.data.0.receipt_type', 'wallet_topup');

        $this->getJson("/api/v1/passenger/receipts/{$receiptId}")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $receiptNumber)
            ->assertJsonPath('data.general.status.key', 'received');
    }

    public function test_electronic_booking_creates_receipts_for_passenger_and_driver(): void
    {
        $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver('driver-receipts@example.com');
        $passenger = $this->createPassenger('Passenger Wallet User', 'passenger-wallet-user@example.com', '0985000002', 50000);
        $trip = $this->createTrip($driver, TripStatus::where('status_key', 'active')->value('status_id'), $damascus, $homs);

        Sanctum::actingAs($passenger);

        $response = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $bookingId = $response->json('data.booking_id');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 10000,
        ]);

        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $passenger->user_id,
            'related_booking_id' => $bookingId,
            'receipt_type' => 'booking_payment',
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $driver->user_id,
            'related_booking_id' => $bookingId,
            'receipt_type' => 'booking_income',
            'status' => 'received',
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/receipts')
            ->assertOk()
            ->assertJsonPath('data.data.0.receipt_type', 'booking_income');
    }

    public function test_canceling_electronic_booking_creates_refund_receipts_for_both_sides(): void
    {
        $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver('driver-refund@example.com');
        $passenger = $this->createPassenger('Passenger Refund', 'passenger-refund@example.com', '0985000003', 50000);
        $trip = $this->createTrip($driver, TripStatus::where('status_key', 'pending')->value('status_id'), $damascus, $homs, now()->addHours(5));

        Sanctum::actingAs($passenger);

        $bookingResponse = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $bookingId = $bookingResponse->json('data.booking_id');

        Booking::query()->whereKey($bookingId)->update([
            'created_at' => now()->subMinutes(45),
        ]);

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.penalty.wallet_refund_amount', 5000);

        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $passenger->user_id,
            'related_booking_id' => $bookingId,
            'receipt_type' => 'booking_refund',
            'amount' => 5000,
        ]);

        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $driver->user_id,
            'related_booking_id' => $bookingId,
            'receipt_type' => 'booking_refund_reversal',
            'amount' => 5000,
        ]);
    }

    private function seedTripStatuses(): void
    {
        TripStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        TripStatus::create([
            'status_key' => 'active',
            'status_name' => 'نشطة',
            'description' => 'Active',
            'is_final' => false,
            'display_order' => 2,
            'is_active' => true,
        ]);

        TripStatus::create([
            'status_key' => 'completed',
            'status_name' => 'منجزة',
            'description' => 'Completed',
            'is_final' => true,
            'display_order' => 3,
            'is_active' => true,
        ]);

        TripStatus::create([
            'status_key' => 'canceled',
            'status_name' => 'ملغاة',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 4,
            'is_active' => true,
        ]);
    }

    private function seedBookingStatuses(): void
    {
        BookingStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
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

        BookingStatus::create([
            'status_key' => 'canceled',
            'status_name' => 'ملغى',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 4,
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

    private function createBackofficeUser(string $roleName, string $fullName, string $email): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createPassenger(string $fullName, string $email, string $phone, float $balance = 0): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $passenger->user_id,
            'balance' => $balance,
        ]);

        return $passenger->fresh('wallet');
    }

    private function createDriver(string $email): User
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Driver Receipt Test',
            'phone' => '097'.fake()->unique()->numerify('#######'),
            'email' => $email,
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

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Kia',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate-p',
            'certified_agency' => '2024',
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
        ]);

        Wallet::create([
            'user_id' => $driver->user_id,
            'balance' => 0,
        ]);

        return $driver->fresh(['wallet']);
    }

    private function createTrip(
        User $driver,
        int $statusId,
        Governorate $start,
        Governorate $end,
        $departureTime = null
    ): Trip
    {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime ?? now()->subMinutes(5),
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
            'route_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
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
            'expected_arrival_time' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 43.2520,
            'longitude' => -126.4530,
            'address' => 'End',
            'sequence_order' => 2,
            'expected_arrival_time' => now()->addMinutes(90),
        ]);

        return $trip;
    }
}
