<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Complaint;
use App\Models\DriverProfile;
use App\Models\DriverReview;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RatingAndHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_list_their_trips_and_complaints_and_rate_completed_trip_once(): void
    {
        $this->seedStatuses();
        [$passenger, $driver] = $this->createPassengerAndDriver();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, TripStatus::COMPLETED, $damascus, $homs);

        $booking = Booking::create([
            'booking_code' => 'RATE-1001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 12000,
            'status_id' => BookingStatus::query()->where('status_key', 'completed')->value('status_id'),
            'completed_at' => now()->subHour(),
        ]);

        Complaint::create([
            'complaint_code' => 'CMP-P-1',
            'complainant_id' => $passenger->user_id,
            'complainant_role' => Role::ROLE_PASSENGER,
            'complaint_type' => 'ride',
            'related_trip_id' => $trip->trip_id,
            'related_booking_id' => $booking->booking_id,
            'related_driver_id' => $driver->user_id,
            'description' => 'Passenger complaint body',
            'status' => 'new',
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/trips')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.trip_id', $trip->trip_id)
            ->assertJsonPath('data.items.0.is_rated', false)
            ->assertJsonPath('data.items.0.rating_endpoint', '/api/v1/passenger/rate-trip/'.$trip->trip_id);

        $this->getJson('/api/v1/passenger/complaints')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.related_trip_id', $trip->trip_id);

        $this->postJson('/api/v1/passenger/rate-trip/'.$trip->trip_id, [
            'stars' => 5,
            'comment' => 'Excellent trip',
        ])
            ->assertCreated()
            ->assertJsonPath('data.trip_id', $trip->trip_id)
            ->assertJsonPath('data.stars', 5)
            ->assertJsonPath('data.booking_id', $booking->booking_id);

        $this->assertDatabaseHas('driver_reviews', [
            'booking_id' => $booking->booking_id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $passenger->user_id,
            'rating' => 5,
            'comment' => 'Excellent trip',
            'is_visible' => true,
        ]);

        $this->postJson('/api/v1/passenger/rate-trip/'.$trip->trip_id, [
            'stars' => 4,
            'comment' => 'Second try',
        ])->assertStatus(422);
    }

    public function test_driver_can_list_own_complaints_and_rating_analytics_with_recent_comments(): void
    {
        $this->seedStatuses();
        [$passenger, $driver] = $this->createPassengerAndDriver();
        [$damascus, $homs] = $this->createGovernorates();

        $completedBookingStatusId = BookingStatus::query()->where('status_key', 'completed')->value('status_id');

        $firstTrip = $this->createTrip($driver, TripStatus::COMPLETED, $damascus, $homs);
        $secondTrip = $this->createTrip($driver, TripStatus::AUTO_COMPLETED, $damascus, $homs);

        $firstBooking = Booking::create([
            'booking_code' => 'DRV-RATE-1',
            'trip_id' => $firstTrip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 15000,
            'status_id' => $completedBookingStatusId,
            'completed_at' => now()->subHours(5),
        ]);

        $secondPassenger = $this->createPassenger('passenger-two@example.com', '0999998899');
        $secondBooking = Booking::create([
            'booking_code' => 'DRV-RATE-2',
            'trip_id' => $secondTrip->trip_id,
            'passenger_id' => $secondPassenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 18000,
            'status_id' => $completedBookingStatusId,
            'completed_at' => now()->subHours(2),
        ]);

        DriverReview::create([
            'booking_id' => $firstBooking->booking_id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $passenger->user_id,
            'rated_user_type' => Role::ROLE_DRIVER,
            'rating' => 1,
            'comment' => 'Very poor trip',
            'is_visible' => true,
        ]);

        DriverReview::create([
            'booking_id' => $secondBooking->booking_id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $secondPassenger->user_id,
            'rated_user_type' => Role::ROLE_DRIVER,
            'rating' => 5,
            'comment' => 'Perfect service',
            'is_visible' => true,
        ]);

        Complaint::create([
            'complaint_code' => 'CMP-D-1',
            'complainant_id' => $driver->user_id,
            'complainant_role' => Role::ROLE_DRIVER,
            'complaint_type' => 'passenger',
            'related_trip_id' => $firstTrip->trip_id,
            'related_driver_id' => $driver->user_id,
            'related_passenger_id' => $passenger->user_id,
            'description' => 'Driver complaint body',
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/complaints')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.complaint_type', 'passenger');

        $this->getJson('/api/v1/driver/rating')
            ->assertOk()
            ->assertJsonPath('data.average_rating', 3)
            ->assertJsonPath('data.total_ratings', 2)
            ->assertJsonPath('data.breakdown.1', 1)
            ->assertJsonPath('data.breakdown.5', 1)
            ->assertJsonFragment([
                'user_id' => $secondPassenger->user_id,
                'phone' => $secondPassenger->phone,
            ])
            ->assertJsonFragment(['comment' => 'Perfect service']);
    }

    public function test_admin_can_filter_hide_ratings_and_list_low_rated_drivers(): void
    {
        $this->seedStatuses();
        $admin = $this->createAdmin();
        [$passenger, $driver] = $this->createPassengerAndDriver('driver-low@example.com', '0999998800');
        [$damascus, $homs] = $this->createGovernorates();

        $completedStatusId = BookingStatus::query()->where('status_key', 'completed')->value('status_id');

        $completedTrip = $this->createTrip($driver, TripStatus::COMPLETED, $damascus, $homs, now()->subDays(2));
        $canceledTrip = $this->createTrip($driver, TripStatus::CANCELED, $damascus, $homs, now()->subDay());

        $completedBooking = Booking::create([
            'booking_code' => 'ADM-RATE-1',
            'trip_id' => $completedTrip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 9000,
            'status_id' => $completedStatusId,
            'completed_at' => now()->subDays(2),
        ]);

        $secondPassenger = $this->createPassenger('passenger-three@example.com', '0999998811');
        $secondCompletedTrip = $this->createTrip($driver, TripStatus::COMPLETED, $damascus, $homs, now()->subHours(10));
        $secondBooking = Booking::create([
            'booking_code' => 'ADM-RATE-2',
            'trip_id' => $secondCompletedTrip->trip_id,
            'passenger_id' => $secondPassenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => $completedStatusId,
            'completed_at' => now()->subHours(10),
        ]);

        $lowReview = DriverReview::create([
            'booking_id' => $completedBooking->booking_id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $passenger->user_id,
            'rated_user_type' => Role::ROLE_DRIVER,
            'rating' => 1,
            'comment' => 'Unsafe behavior',
            'is_visible' => true,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        DriverReview::create([
            'booking_id' => $secondBooking->booking_id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $secondPassenger->user_id,
            'rated_user_type' => Role::ROLE_DRIVER,
            'rating' => 1,
            'comment' => 'Driver arrived late',
            'is_visible' => true,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/ratings/'.$lowReview->review_id)
            ->assertOk()
            ->assertJsonPath('data.rating_id', $lowReview->review_id)
            ->assertJsonPath('data.booking_id', $completedBooking->booking_id)
            ->assertJsonPath('data.trip_id', $completedTrip->trip_id)
            ->assertJsonPath('data.stars', 1)
            ->assertJsonPath('data.classification', 'low')
            ->assertJsonPath('data.comment', 'Unsafe behavior')
            ->assertJsonPath('data.is_visible', true)
            ->assertJsonPath('data.rated_user.user_id', $driver->user_id)
            ->assertJsonPath('data.author.user_id', $passenger->user_id);

        $this->getJson('/api/v1/admin/ratings/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Rating not found.');

        $this->getJson('/api/v1/admin/ratings?user_type=driver&from_date='.now()->subDays(3)->toDateString().'&to_date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.summary.average_rating', 1)
            ->assertJsonPath('data.summary.total_ratings', 2)
            ->assertJsonPath('data.summary.breakdown.1', 2)
            ->assertJsonPath('data.summary.classification_counts.low', 2)
            ->assertJsonPath('data.summary.visible_ratings_count', 2)
            ->assertJsonPath('data.items.0.rated_user_type', 'driver')
            ->assertJsonPath('data.items.0.rated_user.phone', $driver->phone)
            ->assertJsonFragment([
                'user_id' => $secondPassenger->user_id,
                'phone' => $secondPassenger->phone,
            ]);

        $this->getJson('/api/v1/admin/ratings/list?user_type=driver&from_date='.now()->subDays(3)->toDateString().'&to_date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.items.0.username', $driver->full_name)
            ->assertJsonPath('data.items.0.user_type', 'driver')
            ->assertJsonPath('data.items.0.stars', 1)
            ->assertJsonPath('data.items.0.rating_status', 'visible')
            ->assertJsonFragment(['comment' => 'Driver arrived late']);

        $this->getJson('/api/v1/admin/ratings?user_type=driver&user_id='.$driver->user_id)
            ->assertOk()
            ->assertJsonPath('data.filters.user_id', $driver->user_id)
            ->assertJsonPath('data.items.0.rated_user.user_id', $driver->user_id);

        $this->getJson('/api/v1/admin/ratings?user_type=passenger&user_id='.$passenger->user_id)
            ->assertOk()
            ->assertJsonPath('data.filters.user_id', $passenger->user_id)
            ->assertJsonPath('data.items.0.author.user_id', $passenger->user_id);

        $this->patchJson('/api/v1/admin/ratings/'.$lowReview->review_id.'/hide')
            ->assertOk()
            ->assertJsonPath('data.rating_id', $lowReview->review_id)
            ->assertJsonPath('data.is_visible', false)
            ->assertJsonPath('data.action', 'hidden');

        $this->assertDatabaseHas('driver_reviews', [
            'review_id' => $lowReview->review_id,
            'is_visible' => false,
            'hidden_by' => $admin->user_id,
        ]);

        $this->patchJson('/api/v1/admin/ratings/'.$lowReview->review_id.'/hide')
            ->assertOk()
            ->assertJsonPath('data.rating_id', $lowReview->review_id)
            ->assertJsonPath('data.is_visible', true)
            ->assertJsonPath('data.action', 'unhidden');

        $this->assertDatabaseHas('driver_reviews', [
            'review_id' => $lowReview->review_id,
            'is_visible' => true,
            'hidden_by' => null,
        ]);

        $this->patchJson('/api/v1/admin/ratings/'.$lowReview->review_id.'/hide')->assertOk();

        $this->getJson('/api/v1/admin/drivers/low-rated')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.driver.user_id', $driver->user_id)
            ->assertJsonPath('data.items.0.average_rating', 1)
            ->assertJsonPath('data.items.0.total_trips', 3)
            ->assertJsonPath('data.items.0.cancelled_trips', 1)
            ->assertJsonPath('data.items.0.cancellation_rate', 33.33);
    }

    private function seedStatuses(): void
    {
        foreach ([
            [TripStatus::PENDING, false],
            [TripStatus::ACTIVE, false],
            [TripStatus::COMPLETED, true],
            [TripStatus::CANCELED, true],
            [TripStatus::AUTO_COMPLETED, true],
        ] as [$key, $isFinal]) {
            TripStatus::query()->updateOrCreate(
                ['status_key' => $key],
                [
                    'status_name' => $key,
                    'description' => $key,
                    'is_final' => $isFinal,
                    'display_order' => 1,
                    'is_active' => true,
                ]
            );
        }

        foreach (['pending', 'accepted', 'completed', 'canceled', 'rejected'] as $statusKey) {
            BookingStatus::query()->updateOrCreate(
                ['status_key' => $statusKey],
                [
                    'status_name' => $statusKey,
                    'description' => $statusKey,
                    'is_final' => in_array($statusKey, ['completed', 'canceled', 'rejected'], true),
                    'display_order' => 1,
                    'is_active' => true,
                ]
            );
        }
    }

    private function createPassengerAndDriver(
        string $driverEmail = 'driver-rating@example.com',
        string $driverPhone = '0999998877'
    ): array {
        return [
            $this->createPassenger(),
            $this->createDriver($driverEmail, $driverPhone),
        ];
    }

    private function createPassenger(
        string $email = 'passenger-rating@example.com',
        string $phone = '0999998866'
    ): User {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => 'Passenger User',
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

    private function createDriver(string $email, string $phone): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Driver User',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($role->id);

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

        Wallet::create([
            'user_id' => $driver->user_id,
            'balance' => 0,
        ]);

        return $driver;
    }

    private function createAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);

        $admin = User::create([
            'full_name' => 'Admin User',
            'phone' => '0999998822',
            'email' => 'admin-ratings@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $admin->roles()->attach($role->id);

        return $admin;
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::query()->firstOrCreate(['name' => 'Damascus'], ['is_active' => true, 'created_at' => now()]),
            Governorate::query()->firstOrCreate(['name' => 'Homs'], ['is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createTrip(
        User $driver,
        string $statusKey,
        Governorate $start,
        Governorate $end,
        $departureTime = null
    ): Trip {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime ?? now()->subHours(6),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.50,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 25000,
            'system_calculated_price' => 15000,
            'route_polyline' => 'encoded',
            'status_id' => TripStatus::query()->where('status_key', $statusKey)->value('status_id'),
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Damascus',
            'sequence_order' => 1,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 34.7308,
            'longitude' => 36.7090,
            'address' => 'Homs',
            'sequence_order' => 2,
        ]);

        return $trip;
    }
}
