<?php

namespace Tests\Feature;

use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\Wallet;
use App\Services\GovernorateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerBookingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_booking_is_accepted_by_default_and_turns_trip_to_shared_only(): void
    {
        [$pendingStatus, $activeStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('passenger1@example.com', '0980000001');
        $trip = $this->createTrip($driver, $activeStatus->status_id, $damascus, $homs);

        Sanctum::actingAs($passenger);

        $response = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 2,
            'payment_method' => 'cash',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status_id', BookingStatus::where('status_key', 'accepted')->value('status_id'));

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'allow_shared' => true,
            'allow_private' => false,
            'available_seats' => 2,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'booking_confirmed_passenger',
        ]);

        $secondPassenger = $this->createPassenger('passenger2@example.com', '0980000002');
        Sanctum::actingAs($secondPassenger);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'private',
            'payment_method' => 'cash',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertNotEquals($pendingStatus->status_id, $activeStatus->status_id);
    }

    public function test_private_booking_turns_trip_to_private_only_and_deducts_wallet(): void
    {
        [, $activeStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('wallet-passenger@example.com', '0980000003', 50000);
        $trip = $this->createTrip($driver, $activeStatus->status_id, $damascus, $homs);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'private',
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'allow_shared' => false,
            'is_private_booked' => true,
            'available_seats' => 0,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'transaction_type' => 'debit',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'wallet_booking_debit',
        ]);
    }

    public function test_new_pickup_point_must_be_on_trip_path(): void
    {
        [$pendingStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();
        $this->fakeGovernorateResolver($damascus);

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('route-passenger@example.com', '0980000004');
        $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'pickup_point' => [
                'point_name' => 'بعيد جداً',
                'latitude' => 35.5000,
                'longitude' => 38.5000,
            ],
        ])->assertStatus(422);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'pickup_point' => [
                'point_type' => 'new point',
                'latitude' => 40.7005,
                'longitude' => -120.9505,
                'note' => 'الانطلاق من الكراج الجنوبي',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('booking_pickup_points', [
            'point_name' => 'الانطلاق من الكراج الجنوبي',
            'address' => 'عنوان محسوب تلقائياً',
            'is_new' => true,
        ]);
    }

    private function seedTripStatuses(): array
    {
        return [
            TripStatus::create([
                'status_key' => 'pending',
                'status_name' => 'قيد الانتظار',
                'description' => 'Pending',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => 'active',
                'status_name' => 'نشطة',
                'description' => 'Active',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => 'completed',
                'status_name' => 'منجزة',
                'description' => 'Completed',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => 'canceled',
                'status_name' => 'ملغاة',
                'description' => 'Canceled',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ]),
        ];
    }

    private function seedBookingStatuses(): void
    {
        BookingStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);
        BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 2,
            'is_active' => true,
        ]);
        BookingStatus::create([
            'status_key' => 'rejected',
            'status_name' => 'مرفوض',
            'description' => 'Rejected',
            'is_final' => true,
            'display_order' => 3,
            'is_active' => true,
        ]);
        BookingStatus::create([
            'status_key' => 'canceled',
            'status_name' => 'ملغى',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 4,
            'is_active' => true,
        ]);
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create(['name' => 'دمشق', 'is_active' => true, 'created_at' => now()]),
            Governorate::create(['name' => 'حمص', 'is_active' => true, 'created_at' => now()]),
        ];
    }

    private function createDriver(): User
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Driver Passenger Test',
            'phone' => '0970000001',
            'email' => 'driver-passenger-test@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($driverRole->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Kia',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate-p',
            'certified_agency' => '2024',
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
        ]);

        return $driver;
    }

    private function createPassenger(string $email, string $phone, float $walletBalance = 0): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => 'Passenger Test',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($role->id);

        if ($walletBalance > 0) {
            Wallet::create([
                'user_id' => $passenger->user_id,
                'balance' => $walletBalance,
            ]);
        }

        return $passenger;
    }

    private function createTrip(User $driver, int $statusId, Governorate $start, Governorate $end): Trip
    {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => now()->subMinutes(5),
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.5,
            'total_seats' => 4,
            'available_seats' => 4,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 30000,
            'system_calculated_price' => 22000,
            'route_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
            'status_id' => $statusId,
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 38.5000,
            'longitude' => -120.2000,
            'address' => 'Start',
            'sequence_order' => 1,
            'expected_arrival_time' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 43.2520,
            'longitude' => -126.4530,
            'address' => 'End',
            'sequence_order' => 2,
            'expected_arrival_time' => now()->addMinutes(90),
        ]);

        return $trip;
    }

    private function fakeGovernorateResolver(Governorate $governorate): void
    {
        $resolver = new class($governorate) extends GovernorateResolverService {
            public function __construct(private Governorate $governorate)
            {
            }

            public function enrichPointsWithAddresses(array $orderedPoints): array
            {
                return collect($orderedPoints)->map(function (array $point) {
                    $point['address'] = 'عنوان محسوب تلقائياً';
                    return $point;
                })->all();
            }

            public function resolveGovernorateIdFromPoint(array $point): int
            {
                return (int) $this->governorate->governorate_id;
            }
        };

        $this->app->instance(GovernorateResolverService::class, $resolver);
    }
}
