<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\DriverReview;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_view_and_update_own_profile()
    {
        Storage::fake('public');

        $passenger = $this->createUserWithRole('John Passenger', 'passenger@example.com', 'passenger');
        Sanctum::actingAs($passenger, ['*']);

        $response = $this->getJson('/api/v1/passenger/me');
        $response->assertOk()
            ->assertJsonStructure(['data' => ['name', 'photo', 'completed_reservations_count', 'cancelled_reservations_count', 'rating']]);

        $updateResponse = $this->patchJson('/api/v1/passenger/me', [
            'name' => 'Updated Passenger',
            'photo' => UploadedFile::fake()->image('profile.jpg'),
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Updated Passenger')
            ->assertStringContainsString('/storage/passengers/profile-photos/', $updateResponse->json('data.photo'));

        $files = Storage::disk('public')->files('passengers/profile-photos');
        $this->assertCount(1, $files);
    }

    public function test_passenger_can_view_other_passenger_public_profile()
    {
        $passenger = $this->createUserWithRole('John Passenger', 'passenger@example.com', 'passenger');
        $otherPassenger = $this->createUserWithRole('Other Passenger', 'other@example.com', 'passenger');
        $driver = $this->createUserWithRole('Driver One', 'driver@example.com', 'driver');

        $this->createStatusesForBooking();

        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'car_type_id' => 1,
            'pickup_address' => 'Point A',
            'dropoff_address' => 'Point B',
            'seats' => 2,
            'price' => 20,
            'status_id' => TripStatus::where('slug', 'completed')->value('status_id'),
            'available_at' => now(),
        ]);

        Booking::create([
            'passenger_id' => $otherPassenger->user_id,
            'trip_id' => $trip->trip_id,
            'status_id' => BookingStatus::where('slug', 'completed')->value('status_id'),
            'payment_id' => null,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($passenger, ['*']);

        $response = $this->getJson(sprintf('/api/v1/passenger/users/%s', $otherPassenger->user_id));

        $response->assertOk();
        $this->assertArrayNotHasKey('email', $response->json('data'));
        $response->assertJsonPath('data.name', 'Other Passenger')
            ->assertJsonPath('data.completed_reservations_count', 1)
            ->assertJsonPath('data.cancelled_reservations_count', 0);
    }

    public function test_passenger_can_view_driver_profile_with_reviews()
    {
        $passenger = $this->createUserWithRole('John Passenger', 'passenger@example.com', 'passenger');
        $driver = $this->createUserWithRole('Driver One', 'driver@example.com', 'driver');

        $this->createStatusesForBooking();

        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'car_type_id' => 1,
            'pickup_address' => 'Point A',
            'dropoff_address' => 'Point B',
            'seats' => 2,
            'price' => 20,
            'status_id' => TripStatus::where('slug', 'completed')->value('status_id'),
            'available_at' => now(),
        ]);

        Booking::create([
            'passenger_id' => $passenger->user_id,
            'trip_id' => $trip->trip_id,
            'status_id' => BookingStatus::where('slug', 'completed')->value('status_id'),
            'payment_id' => null,
            'booked_at' => now(),
        ]);

        DriverReview::forceCreate([
            'driver_id' => $driver->user_id,
            'passenger_id' => $passenger->user_id,
            'rating' => 5,
            'comment' => 'Excellent',
            'is_visible' => true,
        ]);

        Sanctum::actingAs($passenger, ['*']);

        $response = $this->getJson(sprintf('/api/v1/passenger/drivers/%s?per_page=1', $driver->user_id));

        $response->assertOk()
            ->assertJsonPath('data.driver_id', $driver->user_id)
            ->assertJsonPath('data.completed_trips_count', 1)
            ->assertJsonPath('data.reviews.0.stars', 5)
            ->assertJsonPath('data.reviews.0.comment', 'Excellent');
    }

    public function test_driver_can_view_own_profile_and_passenger_profile()
    {
        $driver = $this->createUserWithRole('Driver One', 'driver@example.com', 'driver');
        $passenger = $this->createUserWithRole('John Passenger', 'passenger@example.com', 'passenger');

        $this->createStatusesForBooking();
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'car_type_id' => 1,
            'pickup_address' => 'Point A',
            'dropoff_address' => 'Point B',
            'seats' => 2,
            'price' => 20,
            'status_id' => TripStatus::where('slug', 'completed')->value('status_id'),
            'available_at' => now(),
        ]);

        Booking::create([
            'passenger_id' => $passenger->user_id,
            'trip_id' => $trip->trip_id,
            'status_id' => BookingStatus::where('slug', 'completed')->value('status_id'),
            'payment_id' => null,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($driver, ['*']);

        $selfResponse = $this->getJson('/api/v1/driver/me');
        $selfResponse->assertOk()
            ->assertJsonPath('data.driver_id', $driver->user_id)
            ->assertJsonPath('data.completed_trips_count', 1);

        $passengerResponse = $this->getJson(sprintf('/api/v1/driver/passengers/%s', $passenger->user_id));
        $passengerResponse->assertOk()
            ->assertJsonPath('data.name', 'John Passenger')
            ->assertJsonPath('data.completed_reservations_count', 1);
    }

    public function test_admin_can_view_own_profile()
    {
        $admin = $this->createUserWithRole('Admin User', 'admin@example.com', 'admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/admin/me');
        $response->assertOk()
            ->assertJsonPath('data.name', 'Admin User')
            ->assertJsonPath('data.email', 'admin@example.com');
    }

    protected function createUserWithRole(string $name, string $email, string $roleSlug): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);

        $user = User::create([
            'full_name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        $user->roles()->syncWithoutDetaching([$role->role_id]);

        return $user;
    }

    protected function createStatusesForBooking(): void
    {
        TripStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        TripStatus::firstOrCreate(['slug' => 'cancelled'], ['name' => 'Cancelled']);
        BookingStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        BookingStatus::firstOrCreate(['slug' => 'cancelled'], ['name' => 'Cancelled']);
    }
}
