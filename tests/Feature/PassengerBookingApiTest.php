<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingCancellation;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripLiveLocation;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\Receipt;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\GovernorateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_canceling_cash_booking_less_than_twelve_hours_blocks_cash_and_restores_capacity(): void
    {
        [$pendingStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('cancel-cash@example.com', '0980000010');
        $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addHours(5));
        $anotherTrip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addHours(8));

        Sanctum::actingAs($passenger);

        $bookingResponse = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 2,
            'payment_method' => 'cash',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $bookingId = $bookingResponse->json('data.booking_id');

        Booking::query()->whereKey($bookingId)->update([
            'created_at' => now()->subMinutes(45),
        ]);

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel", [
            'reason' => 'تغيير الخطة',
        ])
            ->assertOk()
            ->assertJsonPath('data.penalty.percentage', 0)
            ->assertJsonPath('data.penalty.rating_penalty', 0.3)
            ->assertJsonPath('data.restriction.type', 'cash_block')
            ->assertJsonPath('data.trip.available_seats', 4);

        $this->assertDatabaseHas('account_restrictions', [
            'user_id' => $passenger->user_id,
            'restriction_type' => 'cash_block',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $bookingId,
            'status_id' => BookingStatus::where('status_key', 'canceled')->value('status_id'),
        ]);

        $this->assertSame('4.70', $passenger->fresh()->rating);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $anotherTrip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'pickup_point' => [
                'trip_point_id' => $anotherTrip->points()->first()->point_id,
            ],
        ])->assertStatus(422);
    }

    public function test_canceling_electronic_booking_within_five_hours_refunds_half_amount(): void
    {
        [$pendingStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('cancel-wallet@example.com', '0980000011', 50000);
        $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addHours(5));

        Sanctum::actingAs($passenger);

        $bookingResponse = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $bookingId = $bookingResponse->json('data.booking_id');

        Booking::query()->whereKey($bookingId)->update([
            'created_at' => now()->subMinutes(45),
        ]);

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.penalty.percentage', 50)
            ->assertJsonPath('data.penalty.amount', 5000)
            ->assertJsonPath('data.penalty.wallet_refund_amount', 5000)
            ->assertJsonPath('data.penalty.rating_penalty', 0.3);

        $this->assertDatabaseHas('booking_cancellations', [
            'booking_id' => $bookingId,
            'penalty_percentage' => 50,
            'penalty_amount' => 5000,
            'wallet_refund_amount' => 5000,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'related_booking_id' => $bookingId,
            'transaction_type' => 'refund',
            'amount' => 5000,
        ]);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $bookingId,
            'payment_status' => 'partially_refunded',
        ]);

        $this->assertSame('45000.00', $passenger->fresh()->wallet->balance);
    }

    public function test_canceling_within_grace_period_has_no_penalty_and_full_refund(): void
    {
        [$pendingStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('cancel-grace@example.com', '0980000012', 30000);
        $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addHour());

        Sanctum::actingAs($passenger);

        $bookingResponse = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $bookingId = $bookingResponse->json('data.booking_id');

        Booking::query()->whereKey($bookingId)->update([
            'created_at' => now()->subMinutes(10),
        ]);

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.penalty.grace_period_applied', true)
            ->assertJsonPath('data.penalty.percentage', 0)
            ->assertJsonPath('data.penalty.amount', 0)
            ->assertJsonPath('data.penalty.wallet_refund_amount', 10000)
            ->assertJsonPath('data.penalty.rating_penalty', 0);

        $this->assertDatabaseMissing('account_restrictions', [
            'user_id' => $passenger->user_id,
        ]);

        $this->assertSame('30000.00', $passenger->fresh()->wallet->balance);
        $this->assertSame('5.00', $passenger->fresh()->rating);
    }

    public function test_seventh_early_cancellation_creates_temporary_ban_and_blocks_new_booking(): void
    {
        [$pendingStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('cancel-ban@example.com', '0980000013', 50000);
        $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addDays(2));
        $anotherTrip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addDays(3));

        $this->seedMonthlyEarlyCancellations($passenger, $trip, 6);

        Sanctum::actingAs($passenger);

        $bookingResponse = $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $trip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $trip->points()->first()->point_id,
            ],
        ])->assertCreated();

        $bookingId = $bookingResponse->json('data.booking_id');

        Booking::query()->whereKey($bookingId)->update([
            'created_at' => now()->subMinutes(45),
        ]);

        $this->postJson("/api/v1/passenger/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.restriction.type', 'temporary_ban')
            ->assertJsonPath('data.penalty.rating_penalty', 0.1);

        $this->assertDatabaseHas('account_restrictions', [
            'user_id' => $passenger->user_id,
            'restriction_type' => 'temporary_ban',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/passenger/bookings', [
            'trip_id' => $anotherTrip->trip_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'pickup_point' => [
                'trip_point_id' => $anotherTrip->points()->first()->point_id,
            ],
        ])->assertStatus(422);
    }

    public function test_passenger_can_view_wallet_with_last_one_hundred_transaction_cards(): void
    {
        [$passenger, $admin] = $this->seedPassengerWalletTransactions();

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.current_balance', 25000)
            ->assertJsonCount(100, 'data.wallet.recent_transactions')
            ->assertJsonPath('data.wallet.recent_transactions.0.title', 'استرداد بعد إلغاء الحجز')
            ->assertJsonPath('data.wallet.recent_transactions.0.formatted_amount', '+12,104')
            ->assertJsonPath('data.wallet.recent_transactions.0.status_label', 'مكتملة')
            ->assertJsonPath('data.wallet.recent_transactions.0.actor_name', 'Wallet Admin')
            ->assertJsonPath('data.wallet.recent_transactions.0.reason', 'استرداد للرحلة 105')
            ->assertJsonPath('data.wallet.recent_transactions.0.details_endpoint', '/api/v1/passenger/wallet/transactions/105');
    }

    public function test_passenger_can_track_driver_only_after_trip_starts(): void
    {
        [, $activeStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('tracking-passenger@example.com', '0980000014');
        $trip = $this->createTrip($driver, $activeStatus->status_id, $damascus, $homs);

        $trip->update([
            'is_tracking_active' => true,
            'tracking_started_at' => now()->subMinutes(10),
            'actual_start_time' => now()->subMinutes(10),
            'last_latitude' => 38.7005,
            'last_longitude' => -120.6505,
            'last_location_at' => now()->subSeconds(15),
        ]);

        Booking::create([
            'booking_code' => 'TRK10001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => BookingStatus::where('status_key', 'accepted')->value('status_id'),
            'confirmed_at' => now(),
        ]);

        TripLiveLocation::create([
            'trip_id' => $trip->trip_id,
            'driver_id' => $driver->user_id,
            'latitude' => 38.6001,
            'longitude' => -120.5001,
            'recorded_at' => now()->subMinute(),
        ]);

        TripLiveLocation::create([
            'trip_id' => $trip->trip_id,
            'driver_id' => $driver->user_id,
            'latitude' => 38.7005,
            'longitude' => -120.6505,
            'speed_kmh' => 42,
            'heading' => 180,
            'accuracy_meters' => 7,
            'recorded_at' => now()->subSeconds(15),
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/trips/'.$trip->trip_id.'/tracking?history_limit=10')
            ->assertOk()
            ->assertJsonPath('data.tracking_available', true)
            ->assertJsonPath('data.tracking_endpoint', '/api/v1/passenger/trips/'.$trip->trip_id.'/tracking')
            ->assertJsonPath('data.driver.full_name', $driver->full_name)
            ->assertJsonPath('data.tracking.last_position.latitude', 38.7005)
            ->assertJsonPath('data.tracking.history.count', 2);
    }

    public function test_passenger_tracking_returns_not_available_before_start(): void
    {
        [$pendingStatus] = $this->seedTripStatuses();
        $this->seedBookingStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $driver = $this->createDriver();
        $passenger = $this->createPassenger('tracking-pending@example.com', '0980000015');
        $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addHour());

        Booking::create([
            'booking_code' => 'TRK10002',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 10000,
            'status_id' => BookingStatus::where('status_key', 'accepted')->value('status_id'),
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/trips/'.$trip->trip_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('data.tracking_available', false)
            ->assertJsonPath('data.message', 'يتم إتاحة تتبع السائق بعد بدء الرحلة فقط.');
    }

    public function test_passenger_can_list_wallet_transactions_and_view_details(): void
    {
        [$passenger, $admin, $transaction, $receipt] = $this->seedPassengerWalletTransactions(true);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/wallet/transactions?search=Wallet Admin&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.data.0.title', 'استرداد بعد إلغاء الحجز')
            ->assertJsonPath('data.data.0.formatted_amount', '+12,104')
            ->assertJsonPath('data.data.0.actor_name', $admin->full_name)
            ->assertJsonPath('data.data.0.details_endpoint', '/api/v1/passenger/wallet/transactions/105')
            ->assertJsonPath('data.total', 105);

        $this->getJson('/api/v1/passenger/wallet/transactions/'.$transaction->transaction_id)
            ->assertOk()
            ->assertJsonPath('data.card.transaction_id', $transaction->transaction_id)
            ->assertJsonPath('data.card.actor_name', $admin->full_name)
            ->assertJsonPath('data.card.details_endpoint', '/api/v1/passenger/wallet/transactions/'.$transaction->transaction_id)
            ->assertJsonPath('data.details.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.details.amount', 12104)
            ->assertJsonPath('data.details.trip.trip_id', $receipt->trip->trip_id);
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

    private function createTrip(
        User $driver,
        int $statusId,
        Governorate $start,
        Governorate $end,
        ?Carbon $departureTime = null
    ): Trip
    {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime ?? now()->subMinutes(5),
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

    private function seedMonthlyEarlyCancellations(User $passenger, Trip $trip, int $count): void
    {
        $canceledStatusId = BookingStatus::where('status_key', 'canceled')->value('status_id');

        for ($i = 0; $i < $count; $i++) {
            $booking = Booking::create([
                'booking_code' => 'HIST'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'trip_id' => $trip->trip_id,
                'passenger_id' => $passenger->user_id,
                'booking_type' => 'shared',
                'seats_reserved' => 1,
                'payment_method' => 'cash',
                'total_amount' => 10000,
                'status_id' => $canceledStatusId,
                'confirmed_at' => now()->subDays(5),
                'canceled_at' => now()->subDays(5),
                'created_at' => now()->subDays(6),
                'updated_at' => now()->subDays(5),
            ]);

            BookingCancellation::create([
                'booking_id' => $booking->booking_id,
                'canceled_by' => $passenger->user_id,
                'reason' => 'سجل سابق',
                'cancellation_time' => now()->subDays(5),
                'hours_before_departure' => 24,
                'penalty_percentage' => 0,
                'penalty_amount' => 0,
                'wallet_refund_amount' => 0,
                'rating_penalty' => 0.1,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ]);
        }
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

    private function seedPassengerWalletTransactions(bool $withTripDetails = false): array
    {
        $passenger = $this->createPassenger(
            $withTripDetails ? 'wallet-passenger-details@example.com' : 'wallet-passenger@example.com',
            $withTripDetails ? '0980000098' : '0980000097',
            25000
        );

        $adminRole = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);
        $admin = User::create([
            'full_name' => 'Wallet Admin',
            'phone' => $withTripDetails ? '0999999996' : '0999999997',
            'email' => $withTripDetails ? 'wallet-admin-passenger-details@example.com' : 'wallet-admin-passenger@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $admin->roles()->attach($adminRole->id);

        $wallet = $passenger->wallet()->first();
        $wallet->update(['balance' => 25000]);

        $trip = null;

        if ($withTripDetails) {
            [$pendingStatus] = $this->seedTripStatuses();
            [$damascus, $homs] = $this->createGovernorates();
            $driver = $this->createDriver();
            $trip = $this->createTrip($driver, $pendingStatus->status_id, $damascus, $homs, now()->addHour());
        }

        $lastTransaction = null;
        $lastReceipt = null;

        for ($i = 0; $i < 105; $i++) {
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->wallet_id,
                'amount' => 12000 + $i,
                'transaction_type' => 'refund',
                'status' => 'completed',
                'transaction_reference' => 'PSG-WALLET-'.$i,
                'description' => 'استرداد للرحلة '.($i + 1),
                'balance_before' => 1000,
                'balance_after' => 2000,
                'performed_by' => $admin->user_id,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);

            $receipt = Receipt::create([
                'receipt_number' => 'RCT-PSG-'.$i,
                'owner_user_id' => $passenger->user_id,
                'wallet_id' => $wallet->wallet_id,
                'related_wallet_transaction_id' => $transaction->transaction_id,
                'related_trip_id' => $trip?->trip_id,
                'receipt_type' => 'booking_refund',
                'direction' => 'credit',
                'status' => 'paid',
                'amount' => 12000 + $i,
                'counterparty_user_id' => $admin->user_id,
                'counterparty_name' => $admin->full_name,
                'reason' => 'استرداد للرحلة '.($i + 1),
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);

            $transaction->update([
                'related_receipt_id' => $receipt->receipt_id,
            ]);

            $lastTransaction = $transaction->fresh();
            $lastReceipt = $receipt->fresh(['trip']);
        }

        if ($withTripDetails) {
            return [$passenger, $admin, $lastTransaction, $lastReceipt];
        }

        return [$passenger, $admin];
    }
}
