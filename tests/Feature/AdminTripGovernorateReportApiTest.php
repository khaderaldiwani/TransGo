<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTripGovernorateReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_trip_activity_report_grouped_by_governates(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN);
        $driver = $this->createDriver();
        $passenger = $this->createPassenger();
        $bookingStatus = BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'Accepted',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $pending = $this->createTripStatus(TripStatus::PENDING);
        $active = $this->createTripStatus(TripStatus::ACTIVE);
        $completed = $this->createTripStatus(TripStatus::COMPLETED);
        $autoCompleted = $this->createTripStatus(TripStatus::AUTO_COMPLETED);
        $canceled = $this->createTripStatus(TripStatus::CANCELED);

        $damascus = $this->createGovernorate('Damascus');
        $homs = $this->createGovernorate('Homs');
        $aleppo = $this->createGovernorate('Aleppo');

        $damascusToHoms = $this->createTrip($driver, $damascus, $homs, $pending, '2026-05-01 09:00:00');
        $this->createBooking($damascusToHoms, $passenger, $bookingStatus, 'BK-REPORT-1');
        $this->createBooking($damascusToHoms, $passenger, $bookingStatus, 'BK-REPORT-2');

        $damascusToAleppo = $this->createTrip($driver, $damascus, $aleppo, $active, '2026-05-02 09:00:00');
        $this->createBooking($damascusToAleppo, $passenger, $bookingStatus, 'BK-REPORT-3');

        $this->createTrip($driver, $homs, $damascus, $completed, '2026-05-03 09:00:00');

        $aleppoToHoms = $this->createTrip($driver, $aleppo, $homs, $autoCompleted, '2026-05-04 09:00:00');
        $this->createBooking($aleppoToHoms, $passenger, $bookingStatus, 'BK-REPORT-4');

        $this->createTrip($driver, $homs, $aleppo, $canceled, '2026-05-05 09:00:00');
        $this->createTrip($driver, $damascus, $homs, $canceled, '2026-04-01 09:00:00');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/reports/trips-by-governorates?date_from=2026-05-01&date_to=2026-05-31');

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.total_trips', 5)
            ->assertJsonPath('data.summary.pending_trips', 1)
            ->assertJsonPath('data.summary.active_trips', 1)
            ->assertJsonPath('data.summary.completed_trips', 2)
            ->assertJsonPath('data.summary.canceled_trips', 1)
            ->assertJsonPath('data.summary.bookings_count', 4)
            ->assertJsonPath('data.by_start_governorate.0.governorate.id', $damascus->governorate_id)
            ->assertJsonPath('data.by_start_governorate.0.total_trips', 2)
            ->assertJsonPath('data.by_start_governorate.0.bookings_count', 3)
            ->assertJsonPath('data.by_start_governorate.0.activity_percentage', 40)
            ->assertJsonPath('data.by_end_governorate.0.governorate.id', $homs->governorate_id)
            ->assertJsonPath('data.by_end_governorate.0.total_trips', 2)
            ->assertJsonPath('data.by_end_governorate.0.bookings_count', 3)
            ->assertJsonPath('data.by_end_governorate.0.activity_percentage', 40);
    }

    public function test_admin_can_filter_trip_activity_report_by_end_governorate(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN);
        $driver = $this->createDriver();
        $passenger = $this->createPassenger();
        $bookingStatus = BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'Accepted',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $pending = $this->createTripStatus(TripStatus::PENDING);
        $autoCompleted = $this->createTripStatus(TripStatus::AUTO_COMPLETED);
        $active = $this->createTripStatus(TripStatus::ACTIVE);

        $damascus = $this->createGovernorate('Damascus');
        $homs = $this->createGovernorate('Homs');
        $aleppo = $this->createGovernorate('Aleppo');

        $tripToHoms = $this->createTrip($driver, $damascus, $homs, $pending, '2026-05-01 09:00:00');
        $this->createBooking($tripToHoms, $passenger, $bookingStatus, 'BK-FILTER-1');

        $this->createTrip($driver, $aleppo, $homs, $autoCompleted, '2026-05-02 09:00:00');
        $this->createTrip($driver, $damascus, $aleppo, $active, '2026-05-03 09:00:00');

        Sanctum::actingAs($admin);

        $response = $this->getJson(
            '/api/v1/admin/reports/trips-by-governorates?date_from=2026-05-01&date_to=2026-05-31&end_governorate_id='.$homs->governorate_id
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.filters.end_governorate_id', $homs->governorate_id)
            ->assertJsonPath('data.summary.total_trips', 2)
            ->assertJsonPath('data.summary.completed_trips', 1)
            ->assertJsonPath('data.by_end_governorate.0.governorate.id', $homs->governorate_id)
            ->assertJsonPath('data.by_end_governorate.0.total_trips', 2)
            ->assertJsonPath('data.by_end_governorate.0.activity_percentage', 100);
    }

    private function createBackofficeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'full_name' => 'Admin Report',
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createDriver(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Driver Report',
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $driver->roles()->attach($role->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'approval_status' => 'approved',
        ]);

        return $driver;
    }

    private function createPassenger(): User
    {
        return User::create([
            'full_name' => 'Passenger Report',
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);
    }

    private function createGovernorate(string $name): Governorate
    {
        return Governorate::create([
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
        ]);
    }

    private function createTripStatus(string $key): TripStatus
    {
        return TripStatus::firstOrCreate(
            ['status_key' => $key],
            [
                'status_name' => $key,
                'description' => $key,
                'is_final' => in_array($key, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED, TripStatus::CANCELED], true),
                'display_order' => 1,
                'is_active' => true,
            ]
        );
    }

    private function createTrip(
        User $driver,
        Governorate $startGovernorate,
        Governorate $endGovernorate,
        TripStatus $status,
        string $departureTime
    ): Trip {
        return Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $startGovernorate->governorate_id,
            'end_governorate_id' => $endGovernorate->governorate_id,
            'departure_time' => $departureTime,
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 120,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 30000,
            'system_calculated_price' => 10000,
            'route_polyline' => 'encoded',
            'status_id' => $status->status_id,
            'created_at' => now(),
        ]);
    }

    private function createBooking(
        Trip $trip,
        User $passenger,
        BookingStatus $bookingStatus,
        string $bookingCode
    ): Booking {
        return Booking::create([
            'booking_code' => $bookingCode,
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $bookingStatus->status_id,
        ]);
    }
}
