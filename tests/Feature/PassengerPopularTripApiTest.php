<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerPopularTripApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_list_top_five_bookable_trips_ordered_by_driver_success_rating_and_departure(): void
    {
        [$pending, $active, $completed, $autoCompleted, $canceled] = $this->seedTripStatuses();
        [$start, $end] = $this->createGovernorates();
        $passenger = $this->createPassenger();

        $topDriver = $this->createDriver('top-driver@example.com', '0972000001', 4.5);
        $sameCompletedHigherRating = $this->createDriver('rating-driver@example.com', '0972000002', 4.9);
        $sameCompletedLowerRating = $this->createDriver('lower-rating-driver@example.com', '0972000003', 4.1);
        $lowerCompleted = $this->createDriver('lower-completed-driver@example.com', '0972000004', 5.0);
        $privateDriver = $this->createDriver('private-driver@example.com', '0972000005', 4.0);
        $inactiveDriver = $this->createDriver('inactive-driver@example.com', '0972000006', 5.0);

        $this->seedCompletedTrips($topDriver, $completed, $autoCompleted, $start, $end, 4);
        $this->seedCompletedTrips($sameCompletedHigherRating, $completed, $autoCompleted, $start, $end, 2);
        $this->seedCompletedTrips($sameCompletedLowerRating, $completed, $autoCompleted, $start, $end, 2);
        $this->seedCompletedTrips($lowerCompleted, $completed, $autoCompleted, $start, $end, 1);
        $this->seedCompletedTrips($privateDriver, $completed, $autoCompleted, $start, $end, 1);
        $this->seedCompletedTrips($inactiveDriver, $completed, $autoCompleted, $start, $end, 8);

        $topTrip = $this->createTrip($topDriver, $pending->status_id, $start, $end, now()->addHours(4));
        $higherRatingTrip = $this->createTrip($sameCompletedHigherRating, $active->status_id, $start, $end, now()->addHours(5));
        $lowerRatingTrip = $this->createTrip($sameCompletedLowerRating, $pending->status_id, $start, $end, now()->addHours(3));
        $lowerCompletedTrip = $this->createTrip($lowerCompleted, $pending->status_id, $start, $end, now()->addHours(2));
        $privateTrip = $this->createTrip($privateDriver, $pending->status_id, $start, $end, now()->addHours(1), false, true);
        $this->createTrip($inactiveDriver, $canceled->status_id, $start, $end, now()->addHour());
        $this->createTrip($inactiveDriver, $pending->status_id, $start, $end, now()->subHour());
        $this->createTrip($inactiveDriver, $pending->status_id, $start, $end, now()->addHours(6), true, false, [
            'available_seats' => 0,
        ]);

        Sanctum::actingAs($passenger);

        $response = $this->getJson('/api/v1/passenger/trips/popular');

        $response
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.meta.total', 5)
            ->assertJsonPath('data.items.0.trip_id', $topTrip->trip_id)
            ->assertJsonPath('data.items.1.trip_id', $higherRatingTrip->trip_id)
            ->assertJsonPath('data.items.2.trip_id', $lowerRatingTrip->trip_id)
            ->assertJsonPath('data.items.3.trip_id', $lowerCompletedTrip->trip_id)
            ->assertJsonPath('data.items.4.trip_id', $privateTrip->trip_id)
            ->assertJsonPath('data.items.4.type.requested', 'private')
            ->assertJsonPath('data.items.0.driver.rating', 4.5)
            ->assertJsonPath('data.items.0.vehicle.image', 'vehicle.jpg')
            ->assertJsonPath('data.items.0.from.display_address', 'Start note')
            ->assertJsonPath('data.items.0.booking_endpoint', '/api/v1/passenger/bookings');
    }

    private function seedTripStatuses(): array
    {
        return [
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::PENDING], [
                'status_key' => TripStatus::PENDING,
                'status_name' => 'قيد الانتظار',
                'description' => 'Pending',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::ACTIVE], [
                'status_key' => TripStatus::ACTIVE,
                'status_name' => 'نشطة',
                'description' => 'Active',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::COMPLETED], [
                'status_key' => TripStatus::COMPLETED,
                'status_name' => 'مكتملة',
                'description' => 'Completed',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::AUTO_COMPLETED], [
                'status_key' => TripStatus::AUTO_COMPLETED,
                'status_name' => 'مكتملة تلقائياً',
                'description' => 'Auto completed',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ]),
            TripStatus::query()->updateOrCreate(['status_key' => TripStatus::CANCELED], [
                'status_key' => TripStatus::CANCELED,
                'status_name' => 'ملغاة',
                'description' => 'Canceled',
                'is_final' => true,
                'display_order' => 5,
                'is_active' => true,
            ]),
        ];
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::query()->create(['name' => 'دمشق', 'is_active' => true, 'created_at' => now()]),
            Governorate::query()->create(['name' => 'حمص', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(string $email, string $phone, float $rating): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::query()->create([
            'full_name' => 'Popular Driver',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'rating' => $rating,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($role->id);

        DriverProfile::query()->create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'personal_photo' => 'driver-photo.jpg',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::query()->create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Kia',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate',
            'certified_agency' => '2026',
        ]);

        VehicleImage::query()->create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
        ]);

        return $driver;
    }

    private function createPassenger(): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::query()->create([
            'full_name' => 'Popular Passenger',
            'phone' => '0982000001',
            'email' => 'popular-passenger@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($role->id);

        return $passenger;
    }

    private function seedCompletedTrips(
        User $driver,
        TripStatus $completed,
        TripStatus $autoCompleted,
        Governorate $start,
        Governorate $end,
        int $count
    ): void {
        for ($index = 0; $index < $count; $index++) {
            $status = $index % 2 === 0 ? $completed : $autoCompleted;

            $this->createTrip(
                $driver,
                $status->status_id,
                $start,
                $end,
                now()->subDays($index + 1)
            );
        }
    }

    private function createTrip(
        User $driver,
        int $statusId,
        Governorate $start,
        Governorate $end,
        $departureTime,
        bool $allowShared = true,
        bool $allowPrivate = false,
        array $overrides = []
    ): Trip {
        $trip = Trip::query()->create(array_merge([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime,
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.5,
            'total_seats' => 4,
            'available_seats' => $allowShared ? 4 : 0,
            'allow_shared' => $allowShared,
            'allow_private' => $allowPrivate,
            'is_private_booked' => false,
            'is_booking_visible' => true,
            'shared_price' => $allowShared ? 10000 : null,
            'private_price' => $allowPrivate ? 30000 : null,
            'system_calculated_price' => 22000,
            'route_polyline' => 'encoded',
            'status_id' => $statusId,
            'created_at' => now(),
        ], $overrides));

        TripPoint::query()->create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Start',
            'note' => 'Start note',
            'sequence_order' => 1,
            'expected_arrival_time' => $departureTime,
        ]);

        TripPoint::query()->create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 34.7308,
            'longitude' => 36.7090,
            'address' => 'End',
            'note' => 'End note',
            'sequence_order' => 2,
            'expected_arrival_time' => Carbon::parse($departureTime)->addMinutes(90),
        ]);

        return $trip->fresh(['points']);
    }
}
