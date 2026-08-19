<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Complaint;
use App\Models\ComplaintStatusLog;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProfilesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_full_driver_profile_with_all_sections(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-driver-profile@example.com');

        $pendingTripStatus = $this->createTripStatus(TripStatus::PENDING, 'Pending');
        $activeTripStatus = $this->createTripStatus(TripStatus::ACTIVE, 'Active');
        $completedTripStatus = $this->createTripStatus(TripStatus::COMPLETED, 'Completed');
        $cancelledTripStatus = $this->createTripStatus(TripStatus::CANCELED, 'Canceled');

        $acceptedBookingStatus = $this->createBookingStatus('accepted', 'Accepted');

        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver('Driver Profile', 'driver-profile@example.com');

        $driverProfile = DriverProfile::query()->where('user_id', $driver->user_id)->firstOrFail();
        $driverProfile->update([
            'address' => 'Damascus Downtown',
            'id_card' => 'storage/drivers/id-cards/driver-card.jpg',
            'license_image' => 'storage/drivers/licenses/driver-license.jpg',
            'personal_photo' => 'storage/drivers/photos/driver-photo.jpg',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driverProfile->user_id,
            'car_type' => 'Kia Cerato',
            'seat_capacity' => 4,
            'mechanical_car' => 'storage/vehicles/contracts/sale-contract.jpg',
            'insurance_image' => 'storage/vehicles/insurance/insurance.jpg',
            'ownership_document' => 'owner_driver_signed_stamp_contract.pdf',
            'certified_agency' => 'storage/vehicles/certified-agency/agency.jpg',
        ]);

        foreach (['front.jpg', 'back.jpg', 'left.jpg', 'right.jpg'] as $imageName) {
            VehicleImage::create([
                'vehicle_id' => $vehicle->id,
                'image_url' => 'storage/vehicles/gallery/'.$imageName,
            ]);
        }

        $passenger = $this->createPassenger('Passenger One', 'passenger-one@example.com');

        $tripStatuses = [
            $pendingTripStatus,
            $activeTripStatus,
            $completedTripStatus,
            $cancelledTripStatus,
        ];

        foreach ($tripStatuses as $index => $tripStatus) {
            $trip = Trip::create([
                'driver_id' => $driver->user_id,
                'start_governorate_id' => $damascus->governorate_id,
                'end_governorate_id' => $homs->governorate_id,
                'departure_time' => now()->addHours($index + 1),
                'estimated_duration_minutes' => 90,
                'estimated_distance_km' => 100,
                'total_seats' => 4,
                'available_seats' => 3,
                'allow_shared' => true,
                'allow_private' => true,
                'is_private_booked' => false,
                'shared_price' => 10000,
                'private_price' => 25000,
                'system_calculated_price' => 12000,
                'status_id' => $tripStatus->status_id,
                'gross_revenue_amount' => 12000 + ($index * 1000),
                'commission_amount' => 1000,
                'net_revenue_amount' => 11000 + ($index * 1000),
                'created_at' => now()->subDays($index + 1),
            ]);

            TripPoint::create([
                'trip_id' => $trip->trip_id,
                'point_type' => 'start',
                'address' => 'Damascus',
                'latitude' => 33.5138,
                'longitude' => 36.2765,
                'sequence_order' => 1,
            ]);

            TripPoint::create([
                'trip_id' => $trip->trip_id,
                'point_type' => 'end',
                'address' => 'Homs',
                'latitude' => 34.7308,
                'longitude' => 36.7090,
                'sequence_order' => 2,
            ]);

            $booking = Booking::create([
                'booking_code' => 'DRV-PROFILE-'.$index,
                'trip_id' => $trip->trip_id,
                'passenger_id' => $passenger->user_id,
                'booking_type' => 'shared',
                'seats_reserved' => 1,
                'payment_method' => 'cash',
                'total_amount' => 12000,
                'status_id' => $acceptedBookingStatus->status_id,
                'created_at' => now()->subDays($index + 1),
                'updated_at' => now()->subDays($index + 1),
            ]);

            DriverReview::create([
                'booking_id' => $booking->booking_id,
                'driver_id' => $driver->user_id,
                'passenger_id' => $passenger->user_id,
                'rated_user_type' => 'driver',
                'rating' => 5,
                'comment' => 'Great ride',
            ]);
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/drivers/'.$driver->user_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.personal_information.full_name', 'Driver Profile')
            ->assertJsonPath(
                'data.personal_information.id_card_image',
                url('/storage/drivers/id-cards/driver-card.jpg')
            )
            ->assertJsonPath('data.personal_information.account_status', 'active')
            ->assertJsonPath('data.vehicle_information.car_type', 'Kia Cerato')
            ->assertJsonPath('data.vehicle_information.sale_contract.contract_validation_flag', true)
            ->assertJsonPath('data.trips_history.total_trips_count', 4)
            ->assertJsonPath('data.trips_history.pending_trips_count', 1)
            ->assertJsonPath('data.trips_history.active_trips_count', 1)
            ->assertJsonPath('data.trips_history.completed_trips_count', 1)
            ->assertJsonPath('data.trips_history.cancelled_trips_count', 1)
            ->assertJsonCount(4, 'data.financial_earnings.trips')
            ->assertJsonPath('data.ratings_reviews.total_ratings_count', 4)
            ->assertJsonPath('data.ratings_reviews.stars_breakdown.5', 4);
    }

    public function test_admin_driver_details_do_not_convert_id_card_number_to_an_image_url(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-id-card-number@example.com');
        $driver = $this->createDriver('Driver Number', 'driver-number@example.com');

        $driver->driverProfile->update(['id_card' => '12478']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/drivers/'.$driver->user_id)
            ->assertOk()
            ->assertJsonPath('data.personal_information.id_card_image', null);
    }

    public function test_admin_can_view_full_passenger_profile_with_all_sections(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-passenger-profile@example.com');
        $passenger = $this->createPassenger('Passenger Profile', 'passenger-profile@example.com');
        $driver = $this->createDriver('Driver For Passenger', 'driver-for-passenger@example.com');

        $completedTripStatus = $this->createTripStatus(TripStatus::COMPLETED, 'Completed');
        $cancelledTripStatus = $this->createTripStatus(TripStatus::CANCELED, 'Canceled');
        $completedBookingStatus = $this->createBookingStatus('completed', 'Completed');
        $cancelledBookingStatus = $this->createBookingStatus('canceled', 'Canceled');
        $rejectedBookingStatus = $this->createBookingStatus('rejected', 'Rejected');
        $pendingBookingStatus = $this->createBookingStatus('pending', 'Pending');

        [$damascus, $homs] = $this->createGovernorates();

        $tripCompleted = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
            'departure_time' => now()->subDay(),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 120,
            'total_seats' => 4,
            'available_seats' => 2,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 11000,
            'private_price' => 25000,
            'system_calculated_price' => 11000,
            'status_id' => $completedTripStatus->status_id,
            'created_at' => now()->subDays(2),
        ]);

        $tripCancelled = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $damascus->governorate_id,
            'end_governorate_id' => $homs->governorate_id,
            'departure_time' => now()->addDay(),
            'estimated_duration_minutes' => 75,
            'estimated_distance_km' => 90,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 9000,
            'private_price' => 21000,
            'system_calculated_price' => 9000,
            'status_id' => $cancelledTripStatus->status_id,
            'created_at' => now()->subDay(),
        ]);

        foreach ([$tripCompleted, $tripCancelled] as $trip) {
            TripPoint::create([
                'trip_id' => $trip->trip_id,
                'point_type' => 'start',
                'address' => 'Damascus',
                'latitude' => 33.5138,
                'longitude' => 36.2765,
                'sequence_order' => 1,
            ]);

            TripPoint::create([
                'trip_id' => $trip->trip_id,
                'point_type' => 'end',
                'address' => 'Homs',
                'latitude' => 34.7308,
                'longitude' => 36.7090,
                'sequence_order' => 2,
            ]);
        }

        Booking::create([
            'booking_code' => 'PAX-PROFILE-1',
            'trip_id' => $tripCompleted->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 11000,
            'status_id' => $completedBookingStatus->status_id,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        Booking::create([
            'booking_code' => 'PAX-PROFILE-2',
            'trip_id' => $tripCancelled->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'private',
            'seats_reserved' => 1,
            'payment_method' => 'wallet',
            'total_amount' => 21000,
            'status_id' => $cancelledBookingStatus->status_id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Booking::create([
            'booking_code' => 'PAX-PROFILE-3',
            'trip_id' => $tripCancelled->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'card',
            'total_amount' => 9000,
            'status_id' => $rejectedBookingStatus->status_id,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]);

        Booking::create([
            'booking_code' => 'PAX-PROFILE-4',
            'trip_id' => $tripCancelled->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 9000,
            'status_id' => $pendingBookingStatus->status_id,
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

        $complaintPending = Complaint::create([
            'complaint_code' => 'CMP-PAX-1',
            'complainant_id' => $passenger->user_id,
            'complainant_role' => Role::ROLE_PASSENGER,
            'complaint_type' => 'driver',
            'related_driver_id' => $driver->user_id,
            'description' => 'Driver issue',
            'status' => 'new',
        ]);

        $complaintResolved = Complaint::create([
            'complaint_code' => 'CMP-PAX-2',
            'complainant_id' => $passenger->user_id,
            'complainant_role' => Role::ROLE_PASSENGER,
            'complaint_type' => 'trip',
            'related_trip_id' => $tripCompleted->trip_id,
            'description' => 'Trip issue',
            'status' => 'completed',
            'resolved_at' => now()->subHour(),
        ]);

        ComplaintStatusLog::create([
            'complaint_id' => $complaintPending->complaint_id,
            'old_status' => null,
            'new_status' => 'new',
            'notes' => 'Pending review',
            'changed_by' => $admin->user_id,
            'changed_at' => now()->subHours(2),
        ]);

        ComplaintStatusLog::create([
            'complaint_id' => $complaintResolved->complaint_id,
            'old_status' => 'in_progress',
            'new_status' => 'completed',
            'notes' => 'Resolved by admin',
            'changed_by' => $admin->user_id,
            'changed_at' => now()->subHour(),
        ]);

        AuditLog::create([
            'actor_user_id' => $admin->user_id,
            'action' => 'passenger.status_toggled',
            'entity_type' => User::class,
            'entity_id' => $passenger->user_id,
            'old_value' => ['account_status' => 1, 'account_status_text' => 'active'],
            'new_value' => ['account_status' => 0, 'account_status_text' => 'suspended', 'action_type' => 'suspend'],
            'description' => 'Suspended passenger account',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/passengers/'.$passenger->user_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.basic_information.full_name', 'Passenger Profile')
            ->assertJsonPath('data.basic_information.mobile_number', $passenger->phone)
            ->assertJsonPath('data.bookings_history.total_bookings_count', 4)
            ->assertJsonPath('data.bookings_history.completed_bookings_count', 1)
            ->assertJsonPath('data.bookings_history.cancelled_bookings_count', 1)
            ->assertJsonPath('data.bookings_history.rejected_bookings_count', 1)
            ->assertJsonPath('data.bookings_history.pending_bookings_count', 1)
            ->assertJsonPath('data.complaints.total_complaints_count', 2)
            ->assertJsonPath('data.complaints.open_complaints_count', 1)
            ->assertJsonPath('data.complaints.resolved_complaints_count', 1)
            ->assertJsonPath('data.admin_action_log.actions.0.action_type', 'suspend');
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

    private function createDriver(string $fullName, string $email): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $user = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $user->roles()->attach($role->id);

        DriverProfile::create([
            'user_id' => $user->user_id,
            'address' => null,
            'id_card' => null,
            'license_image' => null,
            'personal_photo' => null,
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        return $user;
    }

    private function createPassenger(string $fullName, string $email): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $user = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createTripStatus(string $key, string $name): TripStatus
    {
        return TripStatus::firstOrCreate(
            ['status_key' => $key],
            [
                'status_name' => $name,
                'description' => $name,
                'is_final' => in_array($key, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED, TripStatus::CANCELED], true),
                'display_order' => 1,
                'is_active' => true,
            ]
        );
    }

    private function createBookingStatus(string $key, string $name): BookingStatus
    {
        return BookingStatus::firstOrCreate(
            ['status_key' => $key],
            [
                'status_name' => $name,
                'description' => $name,
                'is_final' => in_array($key, ['rejected', 'canceled', 'completed'], true),
                'display_order' => 1,
                'is_active' => true,
            ]
        );
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::firstOrCreate(['name' => 'Damascus'], ['is_active' => true, 'created_at' => now()]),
            Governorate::firstOrCreate(['name' => 'Homs'], ['is_active' => true, 'created_at' => now()]),
        ];
    }
}
