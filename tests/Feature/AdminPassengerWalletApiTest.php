<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Complaint;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPassengerWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_top_up_passenger_wallet_and_create_related_records(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-passenger@example.com');
        $passenger = $this->createPassenger('Passenger Wallet', 'passenger-wallet@example.com');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 175.25,
            'reason' => 'Manual recharge by admin',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.wallet.balance', '175.25')
            ->assertJsonPath('data.transaction.transaction_type', 'topup')
            ->assertJsonPath('data.transaction.status', 'completed');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->user_id,
            'balance' => 175.25,
        ]);

        $walletId = Wallet::query()->where('user_id', $passenger->user_id)->value('wallet_id');

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $walletId,
            'amount' => 175.25,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'performed_by' => $admin->user_id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->user_id,
            'action' => 'wallet.topup',
            'entity_type' => Wallet::class,
            'entity_id' => $walletId,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'wallet_topped_up',
            'target_role' => Role::ROLE_PASSENGER,
            'created_by' => $admin->user_id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->user_id,
            'is_sent' => true,
        ]);
    }

    public function test_admin_top_up_creates_wallet_for_passenger_if_missing(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-create-wallet@example.com');
        $passenger = $this->createPassenger('Passenger No Wallet', 'passenger-no-wallet@example.com', false);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 50,
        ])->assertOk();

        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->user_id,
            'balance' => 50,
        ]);
    }

    public function test_employee_cannot_top_up_passenger_wallet(): void
    {
        $employee = $this->createBackofficeUser(Role::ROLE_EMPLOYEE, 'Employee User', 'employee-passenger@example.com');
        $passenger = $this->createPassenger('Passenger Wallet', 'passenger-wallet-employee@example.com');

        Sanctum::actingAs($employee);

        $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 50,
        ])->assertForbidden();
    }

    public function test_admin_cannot_top_up_passenger_wallet_with_negative_amount(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-negative-passenger@example.com');
        $passenger = $this->createPassenger('Passenger Wallet', 'passenger-negative@example.com');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => -10,
        ])->assertStatus(422);
    }

    public function test_admin_can_filter_passenger_wallet_topups_by_passenger_and_date(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-filter-passenger@example.com');
        $firstPassenger = $this->createPassenger('First Passenger', 'first-passenger@example.com');
        $secondPassenger = $this->createPassenger('Second Passenger', 'second-passenger@example.com');

        $firstTransaction = WalletTransaction::create([
            'wallet_id' => $firstPassenger->wallet->wallet_id,
            'amount' => 120,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'transaction_reference' => 'PAX-FIRST',
            'description' => 'First topup',
            'balance_before' => 0,
            'balance_after' => 120,
            'performed_by' => $admin->user_id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        WalletTransaction::create([
            'wallet_id' => $secondPassenger->wallet->wallet_id,
            'amount' => 220,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'transaction_reference' => 'PAX-SECOND',
            'description' => 'Second topup',
            'balance_before' => 0,
            'balance_after' => 220,
            'performed_by' => $admin->user_id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admin/passenger-wallet-topups?passenger_id={$firstPassenger->user_id}&date_from=".now()->subDays(2)->toDateString().'&date_to='.now()->toDateString());

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.transaction_id', $firstTransaction->transaction_id)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_admin_can_search_passengers_by_id_and_receive_wallet_data(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-search-passenger@example.com');
        $passenger = $this->createPassenger('Searchable Passenger', 'search-passenger@example.com');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/passengers?search='.$passenger->user_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.user_id', $passenger->user_id)
            ->assertJsonPath('data.data.0.wallet.balance', '0.00');
    }

    public function test_admin_can_view_passenger_account_details_with_booking_history(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-passenger-show@example.com');
        $passenger = $this->createPassenger('Passenger Details', 'passenger-details@example.com');
        $passenger->update([
            'registration_type' => User::REGISTRATION_SELF,
            'created_at' => now()->subDays(5),
        ]);
        $passenger->wallet()->update(['balance' => 85.50]);

        $completedTripStatus = TripStatus::create([
            'status_key' => TripStatus::COMPLETED,
            'status_name' => 'Completed',
            'description' => 'Completed',
            'is_final' => true,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $cancelledTripStatus = TripStatus::create([
            'status_key' => TripStatus::CANCELED,
            'status_name' => 'Canceled',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 2,
            'is_active' => true,
        ]);

        $completedBookingStatus = BookingStatus::create([
            'status_key' => 'completed',
            'status_name' => 'Completed',
            'description' => 'Completed',
            'is_final' => true,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $cancelledBookingStatus = BookingStatus::create([
            'status_key' => 'canceled',
            'status_name' => 'Canceled',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 2,
            'is_active' => true,
        ]);

        [$damascus, $homs] = $this->createGovernorates();
        $driver = $this->createDriver('Driver User', 'show-passenger-driver@example.com');

        $completedTrip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
            'departure_time' => now()->subDay(),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150,
            'total_seats' => 4,
            'available_seats' => 2,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 25000,
            'system_calculated_price' => 15000,
            'route_polyline' => 'encoded',
            'status_id' => $completedTripStatus->status_id,
            'created_at' => now()->subDays(2),
        ]);

        $cancelledTrip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
            'departure_time' => now()->subHours(5),
            'estimated_duration_minutes' => 60,
            'estimated_distance_km' => 120,
            'total_seats' => 4,
            'available_seats' => 4,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 25000,
            'system_calculated_price' => 15000,
            'route_polyline' => 'encoded',
            'status_id' => $cancelledTripStatus->status_id,
            'created_at' => now()->subDay(),
        ]);

        foreach ([$completedTrip, $cancelledTrip] as $trip) {
            TripPoint::create([
                'trip_id' => $trip->trip_id,
                'point_type' => 'start',
                'latitude' => 33.5,
                'longitude' => 36.2,
                'address' => 'Damascus',
                'sequence_order' => 1,
            ]);

            TripPoint::create([
                'trip_id' => $trip->trip_id,
                'point_type' => 'end',
                'latitude' => 34.7,
                'longitude' => 36.7,
                'address' => 'Homs',
                'sequence_order' => 2,
            ]);
        }

        $completedBooking = Booking::create([
            'booking_code' => 'PAX-SHOW-1',
            'trip_id' => $completedTrip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $completedBookingStatus->status_id,
            'completed_at' => now()->subHours(20),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $cancelledBooking = Booking::create([
            'booking_code' => 'PAX-SHOW-2',
            'trip_id' => $cancelledTrip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'private',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'total_amount' => 25000,
            'status_id' => $cancelledBookingStatus->status_id,
            'canceled_at' => now()->subHours(3),
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]);

        Complaint::create([
            'complaint_code' => 'CMP-PAX-DETAILS',
            'complainant_id' => $passenger->user_id,
            'complainant_role' => Role::ROLE_PASSENGER,
            'complaint_type' => 'ride',
            'related_trip_id' => $completedTrip->trip_id,
            'related_booking_id' => $completedBooking->booking_id,
            'related_driver_id' => $driver->user_id,
            'description' => 'Passenger issue',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/passengers/{$passenger->user_id}")
            ->assertOk()
            ->assertJsonPath('data.account_details.id', $passenger->user_id)
            ->assertJsonPath('data.account_details.name', $passenger->full_name)
            ->assertJsonPath('data.account_details.phone', $passenger->phone)
            ->assertJsonPath('data.account_details.email', $passenger->email)
            ->assertJsonPath('data.account_details.wallet_amount', 85.5)
            ->assertJsonPath('data.account_details.completed_trips_count', 1)
            ->assertJsonPath('data.account_details.completed_bookings_count', 1)
            ->assertJsonPath('data.account_details.cancelled_trips_count', 1)
            ->assertJsonPath('data.account_details.cancelled_bookings_count', 1)
            ->assertJsonPath('data.account_info.status.value', User::STATUS_ACTIVE)
            ->assertJsonPath('data.account_info.registration_method', User::REGISTRATION_SELF)
            ->assertJsonPath('data.account_info.number_of_complaints', 1)
            ->assertJsonPath('data.bookings.0.booking_id', $cancelledBooking->booking_id)
            ->assertJsonPath('data.bookings.0.trip_id', $cancelledTrip->trip_id)
            ->assertJsonPath('data.bookings.0.period.minutes', 60)
            ->assertJsonPath('data.bookings.0.type', 'private')
            ->assertJsonPath('data.bookings.0.status.key', 'canceled')
            ->assertJsonPath('data.bookings.0.payment_method', 'electronic')
            ->assertJsonPath('data.bookings.1.booking_id', $completedBooking->booking_id)
            ->assertJsonPath('data.bookings.1.period.text', '1 hour 30 minutes');
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

    private function createPassenger(string $fullName, string $email, bool $withWallet = true): User
    {
        $passengerRole = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($passengerRole->id);

        if ($withWallet) {
            Wallet::create([
                'user_id' => $passenger->user_id,
                'balance' => 0,
            ]);
        }

        return $passenger->fresh('wallet');
    }

    private function createDriver(string $fullName, string $email): User
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($driverRole->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'personal_photo' => 'driver.jpg',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Toyota',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanical.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate-123',
            'certified_agency' => 'agency',
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
        ]);

        return $driver;
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create([
                'name' => 'Damascus',
                'is_active' => true,
                'created_at' => now(),
            ]),
            Governorate::create([
                'name' => 'Homs',
                'is_active' => true,
                'created_at' => now(),
            ]),
        ];
    }
}
