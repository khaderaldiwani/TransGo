<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripLiveLocation;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripTrackingShareApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_create_public_tracking_share_and_guest_can_view_sanitized_tracking(): void
    {
        config(['app.tracking_web_url' => 'https://tracking-web-xkqw.onrender.com']);

        [$active] = $this->seedTripStatuses();
        $accepted = $this->seedBookingStatus();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver();
        $passenger = $this->createPassenger();
        $trip = $this->createTrip($driver, $active, $start, $end);

        $booking = Booking::query()->create([
            'booking_code' => 'SHARE-TRACK-1',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $accepted->status_id,
            'confirmed_at' => now(),
        ]);

        TripLiveLocation::query()->create([
            'trip_id' => $trip->trip_id,
            'driver_id' => $driver->user_id,
            'latitude' => 33.6001,
            'longitude' => 36.3001,
            'speed_kmh' => 45,
            'heading' => 170,
            'accuracy_meters' => 8,
            'recorded_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $shareResponse = $this->postJson("/api/v1/passenger/trips/{$trip->trip_id}/tracking/share", [
            'expires_in_minutes' => 60,
        ]);

        $shareResponse
            ->assertCreated()
            ->assertJsonPath('data.trip_id', $trip->trip_id)
            ->assertJsonPath('data.booking_id', $booking->booking_id)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'share_url',
                    'public_path',
                    'api_endpoint',
                    'expires_at',
                ],
            ]);

        $token = $shareResponse->json('data.token');

        $shareResponse->assertJsonPath(
            'data.share_url',
            "https://tracking-web-xkqw.onrender.com/tracking/share/{$token}"
        );

        $publicResponse = $this->getJson("/api/v1/public/tracking/{$token}");

        $publicResponse
            ->assertOk()
            ->assertJsonPath('data.trip_id', $trip->trip_id)
            ->assertJsonPath('data.tracking_available', true)
            ->assertJsonPath('data.driver.full_name', $driver->full_name)
            ->assertJsonPath('data.tracking.last_position.latitude', 33.6001)
            ->assertJsonMissingPath('data.driver.phone')
            ->assertJsonMissingPath('data.booking')
            ->assertJsonMissingPath('data.passenger');
    }

    public function test_public_tracking_share_expires(): void
    {
        [$active] = $this->seedTripStatuses();
        $accepted = $this->seedBookingStatus();
        [$start, $end] = $this->createGovernorates();
        $driver = $this->createDriver('expired-driver@example.com', '0973000002');
        $passenger = $this->createPassenger('expired-passenger@example.com', '0983000002');
        $trip = $this->createTrip($driver, $active, $start, $end);

        Booking::query()->create([
            'booking_code' => 'SHARE-TRACK-2',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $accepted->status_id,
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $token = $this->postJson("/api/v1/passenger/trips/{$trip->trip_id}/tracking/share", [
            'expires_in_minutes' => 5,
        ])->json('data.token');

        $this->travel(6)->minutes();

        $this->getJson("/api/v1/public/tracking/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('success', false);
    }

    private function seedTripStatuses(): array
    {
        return [
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::ACTIVE], [
                'status_name' => 'نشطة',
                'description' => 'Active',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ]),
        ];
    }

    private function seedBookingStatus(): BookingStatus
    {
        return BookingStatus::query()->create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::query()->create(['name' => 'دمشق', 'is_active' => true, 'created_at' => now()]),
            Governorate::query()->create(['name' => 'حمص', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(string $email = 'share-driver@example.com', string $phone = '0973000001'): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::query()->create([
            'full_name' => 'Share Driver',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'rating' => 4.8,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($role->id);

        DriverProfile::query()->create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        return $driver;
    }

    private function createPassenger(string $email = 'share-passenger@example.com', string $phone = '0983000001'): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::query()->create([
            'full_name' => 'Share Passenger',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($role->id);

        return $passenger;
    }

    private function createTrip(User $driver, TripStatus $status, Governorate $start, Governorate $end): Trip
    {
        $trip = Trip::query()->create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => now()->subMinutes(10),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.5,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => false,
            'is_private_booked' => false,
            'is_booking_visible' => true,
            'shared_price' => 10000,
            'system_calculated_price' => 22000,
            'route_polyline' => 'encoded',
            'status_id' => $status->status_id,
            'created_at' => now(),
            'actual_start_time' => now()->subMinutes(10),
            'is_tracking_active' => true,
            'tracking_started_at' => now()->subMinutes(10),
            'last_latitude' => 33.6001,
            'last_longitude' => 36.3001,
            'last_speed_kmh' => 45,
            'last_heading' => 170,
            'last_accuracy_meters' => 8,
            'last_location_at' => now(),
        ]);

        TripPoint::query()->create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Start',
            'sequence_order' => 1,
            'expected_arrival_time' => now()->subMinutes(10),
        ]);

        TripPoint::query()->create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 34.7308,
            'longitude' => 36.7090,
            'address' => 'End',
            'sequence_order' => 2,
            'expected_arrival_time' => now()->addMinutes(80),
        ]);

        return $trip;
    }
}
