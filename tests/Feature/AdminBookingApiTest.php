<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPickupPoint;
use App\Models\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\BookingAttendanceStatus;
use App\Models\DriverProfile;
use App\Models\DriverReview;
use App\Models\Governorate;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBookingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_bookings_grouped_by_trip_with_search_and_filters(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');

        $acceptedBookingStatus = BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 2,
            'is_active' => true,
        ]);

        $pendingBookingStatus = BookingStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$damascus, $homs] = $this->createGovernorates();

        $firstTrip = $this->createTripScenario([
            'driver_name' => 'أحمد السائق',
            'departure_time' => now()->addHour(),
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        $secondTrip = $this->createTripScenario([
            'driver_name' => 'سليم النجار',
            'departure_time' => now()->addHours(4),
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        $passengerA = User::create([
            'full_name' => 'راكب أحمد',
            'phone' => '0999999901',
            'email' => 'passenger-a@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passengerB = User::create([
            'full_name' => 'ركاب سليم',
            'phone' => '0999999902',
            'email' => 'passenger-b@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        Booking::create([
            'booking_code' => 'BK-1001',
            'trip_id' => $firstTrip->trip_id,
            'passenger_id' => $passengerA->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 12000,
            'status_id' => $acceptedBookingStatus->status_id,
        ]);

        Booking::create([
            'booking_code' => 'BK-1002',
            'trip_id' => $secondTrip->trip_id,
            'passenger_id' => $passengerB->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'total_amount' => 14000,
            'status_id' => $pendingBookingStatus->status_id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/bookings?search=أحمد&status=accepted&payment_method=cash');

        $response
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $firstTrip->trip_id)
            ->assertJsonPath('data.items.0.bookings.0.passenger_name', 'راكب أحمد')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.summary.booking_count', 1);
        $response
            ->assertJsonPath('data.items.0.bookings.0.payment_method', 'cash')
            ->assertJsonPath('data.items.0.bookings.0.payment_method_display', 'Cash')
            ->assertJsonPath('data.items.0.bookings.0.status_display', 'Accepted');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/bookings?status=accepted&payment_method=cash')
            ->assertOk()
            ->assertJsonPath('data.items.0.bookings.0.payment_method', 'cash')
            ->assertJsonPath('data.items.0.bookings.0.payment_method_display', 'نقداً')
            ->assertJsonPath('data.items.0.bookings.0.status_display', 'مقبول');
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

    private function createGovernorates(): array
    {
        $damascus = Governorate::create(['name' => 'دمشق']);
        $homs = Governorate::create(['name' => 'حمص']);

        return [$damascus, $homs];
    }

    private function createTripScenario(array $attributes): Trip
    {
        $driver = User::create([
            'full_name' => $attributes['driver_name'],
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $driverProfile = DriverProfile::create([
            'user_id' => $driver->user_id,
            'personal_photo' => null,
            'address' => null,
            'approval_status' => 'approved',
        ]);

        $pendingTripStatus = TripStatus::firstOrCreate(
            ['status_key' => 'pending'],
            [
                'status_name' => 'قيد الانتظار',
                'description' => 'Pending',
                'is_final' => false,
                'is_active' => true,
                'display_order' => 1,
            ]
        );

        $trip = Trip::create([
            'driver_id' => $driverProfile->user_id,
            'start_governorate_id' => $attributes['start_governorate_id'],
            'end_governorate_id' => $attributes['end_governorate_id'],
            'departure_time' => $attributes['departure_time'] ?? now()->addHours(2),
            'estimated_duration_minutes' => 120,
            'estimated_distance_km' => 120.00,
            'total_seats' => 40,
            'available_seats' => 39,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 15000,
            'private_price' => 40000,
            'system_calculated_price' => 15000,
            'route_polyline' => null,
            'status_id' => $pendingTripStatus->status_id,
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'address' => 'Point A',
            'latitude' => 33.0,
            'longitude' => 36.0,
            'sequence_order' => 1,
        ]);

        return $trip;
    }
}
