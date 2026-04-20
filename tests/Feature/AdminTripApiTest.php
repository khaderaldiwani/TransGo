<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttendanceStatus;
use App\Models\BookingPickupPoint;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\DriverReview;
use App\Models\Governorate;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripLiveLocation;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTripApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_trips_with_search_and_filters(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');

        $statusPending = $this->createTripStatus(TripStatus::PENDING, 'قيد الانتظار');
        $statusActive = $this->createTripStatus(TripStatus::ACTIVE, 'نشطة');
        [$damascus, $homs] = $this->createGovernorates();

        $firstTrip = $this->createTripScenario([
            'driver_name' => 'أحمد السائق',
            'status_id' => $statusPending->status_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        $this->createTripScenario([
            'driver_name' => 'سليم النجار',
            'status_id' => $statusActive->status_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/trips?search='.$firstTrip->trip_id.'&driver_name=أحمد');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.trip_id', $firstTrip->trip_id)
            ->assertJsonPath('data.items.0.driver.full_name', 'أحمد السائق')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_admin_can_view_full_trip_details(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $this->createTripStatus(TripStatus::PENDING, 'قيد الانتظار');
        $activeStatus = $this->createTripStatus(TripStatus::ACTIVE, 'نشطة');
        $acceptedBookingStatus = BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);
        $attendanceStatus = BookingAttendanceStatus::create([
            'status_key' => 'present',
            'status_name' => 'حاضر',
            'description' => 'Present',
            'is_final' => true,
            'display_order' => 1,
            'is_active' => true,
        ]);
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTripScenario([
            'driver_name' => 'خالد السائق',
            'status_id' => $activeStatus->status_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        $passenger = User::create([
            'full_name' => 'راكب الاختبار',
            'phone' => '0999999911',
            'email' => 'passenger@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $booking = Booking::create([
            'booking_code' => 'BK-1001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 20000,
            'status_id' => $acceptedBookingStatus->status_id,
            'attendance_status_id' => $attendanceStatus->status_id,
            'notes' => 'راكب VIP',
            'confirmed_at' => now(),
        ]);

        BookingPickupPoint::create([
            'booking_id' => $booking->booking_id,
            'trip_point_id' => $trip->points()->first()->point_id,
            'governorate_id' => $damascus->governorate_id,
            'point_name' => 'موقف البولمان',
            'address' => 'البرامكة',
            'latitude' => 33.5138000,
            'longitude' => 36.2765000,
            'meeting_time' => now()->addMinutes(15),
            'is_new' => false,
        ]);

        Payment::create([
            'booking_id' => $booking->booking_id,
            'payment_method' => 'cash',
            'amount' => 20000,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        DriverReview::create([
            'booking_id' => $booking->booking_id,
            'driver_id' => $trip->driver_id,
            'passenger_id' => $passenger->user_id,
            'rating' => 5,
            'comment' => 'رحلة ممتازة',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/trips/'.$trip->trip_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.trip_id', $trip->trip_id)
            ->assertJsonPath('data.driver.full_name', 'خالد السائق')
            ->assertJsonPath('data.route.from', 'دمشق')
            ->assertJsonPath('data.booking_info.bookings.0.booking_code', 'BK-1001')
            ->assertJsonPath('data.booking_info.bookings.0.passenger.full_name', 'راكب الاختبار');
    }

    public function test_admin_can_fetch_delayed_trips_and_cancel_trip_with_notifications(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $employee = $this->createBackofficeUser(Role::ROLE_EMPLOYEE, 'Employee User', 'employee@example.com');
        $activeStatus = $this->createTripStatus(TripStatus::ACTIVE, 'نشطة');
        $this->createTripStatus(TripStatus::CANCELED, 'ملغاة');
        $acceptedBookingStatus = BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTripScenario([
            'driver_name' => 'متأخر جداً',
            'status_id' => $activeStatus->status_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
            'departure_time' => now()->subHours(3),
            'estimated_duration_minutes' => 60,
        ]);

        $passenger = User::create([
            'full_name' => 'راكب متأثر',
            'phone' => '0999999922',
            'email' => 'affected-passenger@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        Booking::create([
            'booking_code' => 'BK-2001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 15000,
            'status_id' => $acceptedBookingStatus->status_id,
        ]);

        Sanctum::actingAs($admin);

        $delayedResponse = $this->getJson('/api/v1/admin/trips/delayed');

        $delayedResponse
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.trip_id', $trip->trip_id)
            ->assertJsonPath('data.items.0.delay.is_delayed', true);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'trip_delay_alert',
            'reference_id' => $trip->trip_id,
        ]);

        $cancelResponse = $this->postJson('/api/v1/admin/trips/'.$trip->trip_id.'/cancel', [
            'reason' => 'حالة طارئة',
        ]);

        $cancelResponse
            ->assertOk()
            ->assertJsonPath('data.status.key', TripStatus::CANCELED);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => TripStatus::query()->where('status_key', TripStatus::CANCELED)->first()->status_id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'trip_canceled_admin',
            'reference_id' => $trip->trip_id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'trip_canceled_passengers',
            'reference_id' => $trip->trip_id,
        ]);

        $this->assertDatabaseCount('user_notifications', 4);
        $this->assertNotNull($employee);
    }

    public function test_admin_can_track_active_trips_with_real_live_locations(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-tracking@example.com');
        $activeStatus = $this->createTripStatus(TripStatus::ACTIVE, 'نشطة');
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTripScenario([
            'driver_name' => 'سائق التتبع',
            'status_id' => $activeStatus->status_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
            'departure_time' => now()->subMinutes(40),
            'estimated_duration_minutes' => 90,
        ]);

        $trip->update([
            'is_tracking_active' => true,
            'tracking_started_at' => now()->subMinutes(35),
            'last_latitude' => 33.8111000,
            'last_longitude' => 36.5111000,
            'last_speed_kmh' => 61.2,
            'last_heading' => 120,
            'last_accuracy_meters' => 5.5,
            'last_location_at' => now()->subSeconds(15),
        ]);

        TripLiveLocation::create([
            'trip_id' => $trip->trip_id,
            'driver_id' => $trip->driver_id,
            'latitude' => 33.7001000,
            'longitude' => 36.4001000,
            'speed_kmh' => 50,
            'heading' => 100,
            'accuracy_meters' => 7,
            'recorded_at' => now()->subMinute(),
        ]);

        TripLiveLocation::create([
            'trip_id' => $trip->trip_id,
            'driver_id' => $trip->driver_id,
            'latitude' => 33.8111000,
            'longitude' => 36.5111000,
            'speed_kmh' => 61.2,
            'heading' => 120,
            'accuracy_meters' => 5.5,
            'recorded_at' => now()->subSeconds(15),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/trips/tracking/active')
            ->assertOk()
            ->assertJsonPath('data.summary.active_count', 1)
            ->assertJsonPath('data.items.0.trip_id', $trip->trip_id)
            ->assertJsonPath('data.items.0.current_position.latitude', 33.8111)
            ->assertJsonPath('data.items.0.has_live_location', true)
            ->assertJsonPath('data.items.0.tracking_endpoint', '/api/v1/admin/trips/'.$trip->trip_id.'/tracking');

        $this->getJson('/api/v1/admin/trips/'.$trip->trip_id)
            ->assertOk()
            ->assertJsonPath('data.monitoring.trip_tracking_endpoint', '/api/v1/admin/trips/'.$trip->trip_id.'/tracking')
            ->assertJsonPath('data.monitoring.current_position.longitude', 36.5111);

        $this->getJson('/api/v1/admin/trips/'.$trip->trip_id.'/tracking?history_limit=10')
            ->assertOk()
            ->assertJsonPath('data.tracking.history.count', 2)
            ->assertJsonPath('data.tracking.last_position.latitude', 33.8111)
            ->assertJsonPath('data.tracking.details_endpoint', '/api/v1/admin/trips/'.$trip->trip_id.'/tracking');
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

    private function createTripStatus(string $key, string $name): TripStatus
    {
        return TripStatus::create([
            'status_key' => $key,
            'status_name' => $name,
            'description' => $name,
            'is_final' => in_array($key, [TripStatus::COMPLETED, TripStatus::CANCELED], true),
            'display_order' => 1,
            'is_active' => true,
        ]);
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::firstOrCreate(['name' => 'دمشق'], ['is_active' => true, 'created_at' => now()]),
            Governorate::firstOrCreate(['name' => 'حمص'], ['is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createTripScenario(array $overrides = []): Trip
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driverUser = User::create([
            'full_name' => $overrides['driver_name'] ?? 'Driver Name',
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $driverUser->roles()->attach($driverRole->id);

        DriverProfile::create([
            'user_id' => $driverUser->user_id,
            'address' => 'Damascus',
            'personal_photo' => 'driver.jpg',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driverUser->user_id,
            'car_type' => 'Toyota',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => '123456',
            'certified_agency' => '2024',
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'car.jpg',
        ]);

        $trip = Trip::create([
            'driver_id' => $driverUser->user_id,
            'start_governorate_id' => $overrides['start_governorate_id'],
            'end_governorate_id' => $overrides['end_governorate_id'],
            'departure_time' => $overrides['departure_time'] ?? now()->addHour(),
            'estimated_duration_minutes' => $overrides['estimated_duration_minutes'] ?? 90,
            'estimated_distance_km' => 150.50,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 15000,
            'private_price' => 40000,
            'system_calculated_price' => 22000,
            'route_polyline' => 'encoded',
            'status_id' => $overrides['status_id'],
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 33.5138000,
            'longitude' => 36.2765000,
            'address' => 'دمشق',
            'sequence_order' => 1,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 34.7308000,
            'longitude' => 36.7090000,
            'address' => 'حمص',
            'sequence_order' => 2,
        ]);

        return $trip->fresh(['points']);
    }
}
