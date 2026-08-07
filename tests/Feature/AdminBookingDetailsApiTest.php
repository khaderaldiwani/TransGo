<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPickupPoint;
use App\Models\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBookingDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_booking_details(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');

        $pendingBookingStatus = BookingStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTripScenario([
            'driver_name' => 'أحمد السائق',
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        $passenger = User::create([
            'full_name' => 'راكب الاختبار',
            'phone' => '0999999901',
            'email' => 'passenger@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $booking = Booking::create([
            'booking_code' => 'BK-3001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 2,
            'payment_method' => 'cash',
            'total_amount' => 22000,
            'status_id' => $pendingBookingStatus->status_id,
            'attendance_status_id' => null,
        ]);

        BookingPickupPoint::create([
            'booking_id' => $booking->booking_id,
            'trip_point_id' => $trip->points()->first()->point_id,
            'governorate_id' => $damascus->governorate_id,
            'point_name' => 'موقف الاختبار',
            'address' => 'البرامكة',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'meeting_time' => now()->addMinutes(20),
            'is_new' => false,
        ]);

        Payment::create([
            'booking_id' => $booking->booking_id,
            'payment_method' => 'cash',
            'amount' => 22000,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/bookings/'.$booking->booking_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.booking_info.booking_code', 'BK-3001')
            ->assertJsonPath('data.passenger_info.full_name', 'راكب الاختبار')
            ->assertJsonPath('data.passenger_info.attendance_status', 'not_recorded')
            ->assertJsonPath('data.pickup_point_info.location_coordinates.lat', '33.5138000')
            ->assertJsonPath('data.trip_info.driver_name', 'أحمد السائق');
        $response
            ->assertJsonPath('data.passenger_info.attendance_status_display', 'Not recorded')
            ->assertJsonPath('data.booking_info.payment_method', 'cash')
            ->assertJsonPath('data.booking_info.payment_method_display', 'Cash')
            ->assertJsonPath('data.booking_info.payment_status', 'paid')
            ->assertJsonPath('data.booking_info.payment_status_display', 'Paid')
            ->assertJsonPath('data.pickup_point_info.point_status_display', 'New');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/bookings/'.$booking->booking_id)
            ->assertOk()
            ->assertJsonPath('data.passenger_info.attendance_status_display', 'غير مسجل')
            ->assertJsonPath('data.booking_info.payment_method_display', 'نقداً')
            ->assertJsonPath('data.booking_info.payment_status_display', 'مدفوع')
            ->assertJsonPath('data.pickup_point_info.point_status_display', 'جديدة');
    }

    public function test_admin_can_update_booking_status_to_rejected(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin2@example.com');

        $pendingBookingStatus = BookingStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $rejectedBookingStatus = BookingStatus::create([
            'status_key' => 'rejected',
            'status_name' => 'مرفوض',
            'description' => 'Rejected',
            'is_final' => true,
            'display_order' => 2,
            'is_active' => true,
        ]);

        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTripScenario([
            'driver_name' => 'سامي السائق',
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
        ]);

        $passenger = User::create([
            'full_name' => 'راكب آخر',
            'phone' => '0999999902',
            'email' => 'passenger2@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $booking = Booking::create([
            'booking_code' => 'BK-3002',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'total_amount' => 18000,
            'status_id' => $pendingBookingStatus->status_id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/v1/admin/bookings/'.$booking->booking_id.'/status', [
            'status' => 'rejected',
            'reason' => 'عدم توفر المقاعد',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.booking_info.status.key', 'rejected')
            ->assertJsonPath('data.booking_info.rejection_cancellation_reason', 'عدم توفر المقاعد');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $booking->booking_id,
            'status_id' => $rejectedBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->booking_id,
            'to_status_id' => $rejectedBookingStatus->status_id,
        ]);
    }

    private function createBackofficeUser(string $roleName, string $fullName, string $email): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'full_name' => $fullName,
            'phone' => '0999999'.mt_rand(100, 999),
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
            'phone' => '0999999'.mt_rand(100, 999),
            'email' => 'driver'.uniqid().'@example.com',
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

        $tripStatus = TripStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $trip = Trip::create([
            'driver_id' => $driverProfile->user_id,
            'start_governorate_id' => $attributes['start_governorate_id'],
            'end_governorate_id' => $attributes['end_governorate_id'],
            'departure_time' => $attributes['departure_time'] ?? now()->addHours(2),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 80.00,
            'total_seats' => 40,
            'available_seats' => 39,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 12000,
            'private_price' => 40000,
            'system_calculated_price' => 12000,
            'route_polyline' => null,
            'status_id' => $tripStatus->status_id,
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'address' => 'نقطة البداية',
            'latitude' => 33.0,
            'longitude' => 36.0,
            'sequence_order' => 1,
        ]);

        return $trip;
    }
}
