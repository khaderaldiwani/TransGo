<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttendanceStatus;
use App\Models\BookingPickupPoint;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverTripApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_list_pending_current_completed_and_canceled_trips(): void
    {
        $driver = $this->createDriverUser();
        [$pending, $active, $completed, $canceled] = $this->seedTripStatuses();
        [$damascus, $homs] = $this->createGovernorates();
        $this->seedBookingStatuses();
        $this->seedAttendanceStatuses();

        $pendingTrip = $this->createTrip($driver, $pending->status_id, now()->addHours(2), $damascus, $homs);
        $autoCurrentTrip = $this->createTrip($driver, $pending->status_id, now()->subMinutes(10), $damascus, $homs);
        $completedTrip = $this->createTrip($driver, $completed->status_id, now()->subDay(), $damascus, $homs);
        $canceledTrip = $this->createTrip($driver, $canceled->status_id, now()->subHours(3), $damascus, $homs);
        $activeTrip = $this->createTrip($driver, $active->status_id, now()->subMinutes(30), $damascus, $homs);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/trips/pending')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $pendingTrip->trip_id);

        $this->getJson('/api/v1/driver/trips/current')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonFragment(['trip_id' => $autoCurrentTrip->trip_id])
            ->assertJsonFragment(['trip_id' => $activeTrip->trip_id]);

        $this->getJson('/api/v1/driver/trips/completed')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $completedTrip->trip_id);

        $this->getJson('/api/v1/driver/trips/canceled')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $canceledTrip->trip_id);
    }

    public function test_driver_can_view_trip_details_with_trip_and_attendance_sections(): void
    {
        $driver = $this->createDriverUser();
        [$pending] = $this->seedTripStatuses();
        [$acceptedStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, $pending->status_id, now()->addHour(), $damascus, $homs);
        $passenger = $this->createPassengerUser();

        $booking = Booking::create([
            'booking_code' => 'DRV-1001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 25000,
            'status_id' => $acceptedStatus->status_id,
            'attendance_status_id' => $notCheckedStatus->status_id,
            'notes' => 'مقعد أمامي',
        ]);

        BookingPickupPoint::create([
            'booking_id' => $booking->booking_id,
            'trip_point_id' => $trip->points()->first()->point_id,
            'governorate_id' => $damascus->governorate_id,
            'point_name' => 'كراج العباسيين',
            'address' => 'دمشق',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'meeting_time' => now()->addMinutes(30),
            'is_new' => false,
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/trips/'.$trip->trip_id)
            ->assertOk()
            ->assertJsonPath('data.trip_id', $trip->trip_id)
            ->assertJsonPath('data.trip_details.departure_location', 'دمشق')
            ->assertJsonMissingPath('data.trip_details.bookings')
            ->assertJsonPath('data.trip_details.bookings_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/bookings')
            ->assertJsonMissingPath('data.attendance')
            ->assertJsonPath('data.trip_details.attendance_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/attendance');

        $this->getJson('/api/v1/driver/trips/'.$trip->trip_id.'/attendance')
            ->assertOk()
            ->assertJsonPath('data.attendance.items.0.passenger_name', $passenger->full_name);
    }

    public function test_driver_can_list_grouped_bookings_and_view_booking_details(): void
    {
        $driver = $this->createDriverUser();
        [$pending] = $this->seedTripStatuses();
        [, $acceptedStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $earlierTrip = $this->createTrip($driver, $pending->status_id, now()->addHour(), $damascus, $homs);
        $laterTrip = $this->createTrip($driver, $pending->status_id, now()->addHours(3), $damascus, $homs);
        $passengerOne = $this->createPassengerUser('group1@example.com', '0999999951');
        $passengerTwo = $this->createPassengerUser('group2@example.com', '0999999952');
        $passengerThree = $this->createPassengerUser('group3@example.com', '0999999953');

        $firstBooking = $this->createDriverBooking($earlierTrip, $passengerOne, $acceptedStatus, $notCheckedStatus, 'DRV-3001', now()->subMinutes(10));
        $secondBooking = $this->createDriverBooking($earlierTrip, $passengerTwo, $acceptedStatus, $notCheckedStatus, 'DRV-3002', now()->subMinutes(5));
        $thirdBooking = $this->createDriverBooking($laterTrip, $passengerThree, $acceptedStatus, $notCheckedStatus, 'DRV-3003', now()->subMinutes(2));

        $notification = Notification::create([
            'title' => 'طلب حجز جديد',
            'body' => 'تم استلام طلب جديد.',
            'notification_type' => 'booking_requested',
            'reference_type' => Booking::class,
            'reference_id' => $secondBooking->booking_id,
            'created_by' => $passengerTwo->user_id,
            'target_role' => Role::ROLE_DRIVER,
            'target_governorate_id' => $damascus->governorate_id,
        ]);

        UserNotification::create([
            'notification_id' => $notification->notification_id,
            'user_id' => $driver->user_id,
            'is_read' => false,
            'is_sent' => true,
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/bookings?status=accepted')
            ->assertOk()
            ->assertJsonPath('data.items.0.trip_id', $earlierTrip->trip_id)
            ->assertJsonPath('data.items.0.bookings.0.booking_id', $secondBooking->booking_id)
            ->assertJsonPath('data.items.0.bookings.0.is_new', true)
            ->assertJsonFragment(['trip_id' => $laterTrip->trip_id]);

        $this->getJson('/api/v1/driver/trips/'.$earlierTrip->trip_id.'/bookings')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $this->getJson('/api/v1/driver/bookings/'.$secondBooking->booking_id)
            ->assertOk()
            ->assertJsonPath('data.passenger.phone', $passengerTwo->phone)
            ->assertJsonPath('data.operations.status_update_endpoint', '/api/v1/driver/bookings/'.$secondBooking->booking_id.'/status');

        $this->assertDatabaseHas('user_notifications', [
            'notification_id' => $notification->notification_id,
            'user_id' => $driver->user_id,
            'is_read' => true,
        ]);
    }

    public function test_driver_can_reject_accept_and_mark_booking_absent(): void
    {
        $driver = $this->createDriverUser();
        [, $active] = $this->seedTripStatuses();
        [, $acceptedStatus, , $rejectedStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus, $presentStatus, $absentStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, $active->status_id, now()->subMinutes(10), $damascus, $homs);
        $passenger = $this->createPassengerUser('status-change@example.com', '0999999960');

        $booking = $this->createDriverBooking(
            $trip,
            $passenger,
            $acceptedStatus,
            $notCheckedStatus,
            'DRV-4001',
            now()->subHour(),
            'electronic',
            12000
        );

        Sanctum::actingAs($driver);

        $this->patchJson('/api/v1/driver/bookings/'.$booking->booking_id.'/status', [
            'status' => 'rejected',
            'reason' => 'المقاعد غير متاحة الآن',
        ])
            ->assertOk()
            ->assertJsonPath('data.booking.status.key', 'rejected');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $booking->booking_id,
            'status_id' => $rejectedStatus->status_id,
        ]);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 4,
        ]);

        $this->patchJson('/api/v1/driver/bookings/'.$booking->booking_id.'/status', [
            'status' => 'accepted',
        ])
            ->assertOk()
            ->assertJsonPath('data.booking.status.key', 'accepted');

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'available_seats' => 3,
        ]);

        $this->patchJson('/api/v1/driver/bookings/'.$booking->booking_id.'/attendance', [
            'attendance_status' => 'absent',
            'notes' => 'لم يحضر إلى نقطة الالتقاء',
        ])
            ->assertOk()
            ->assertJsonPath('data.booking.attendance_status.key', 'absent');

        $this->assertDatabaseHas('booking_attendances', [
            'booking_id' => $booking->booking_id,
            'status_id' => $absentStatus->status_id,
            'penalty_amount' => 12000,
            'rating_penalty' => 0.3,
        ]);

        $this->assertSame('4.70', $passenger->fresh()->rating);
    }

    public function test_driver_can_cancel_trip_and_related_bookings(): void
    {
        $driver = $this->createDriverUser();
        [$pending, $active, $completed, $canceledTripStatus] = $this->seedTripStatuses();
        [$pendingBookingStatus, $acceptedBookingStatus, $canceledBookingStatus] = $this->seedBookingStatuses();
        $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, $active->status_id, now()->addMinutes(30), $damascus, $homs);
        $passengerOne = $this->createPassengerUser('pass1@example.com', '0999999911');
        $passengerTwo = $this->createPassengerUser('pass2@example.com', '0999999922');

        Booking::create([
            'booking_code' => 'DRV-2001',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passengerOne->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 20000,
            'status_id' => $pendingBookingStatus->status_id,
        ]);

        Booking::create([
            'booking_code' => 'DRV-2002',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passengerTwo->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'cash',
            'total_amount' => 18000,
            'status_id' => $acceptedBookingStatus->status_id,
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/cancel', [
            'reason' => 'عطل مفاجئ في المركبة',
        ])->assertOk()
            ->assertJsonPath('data.classification.key', 'canceled');

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => $canceledTripStatus->status_id,
        ]);

        $this->assertDatabaseCount('booking_cancellations', 2);
        $this->assertDatabaseCount('booking_status_logs', 2);
        $this->assertDatabaseHas('bookings', [
            'booking_code' => 'DRV-2001',
            'status_id' => $canceledBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_code' => 'DRV-2002',
            'status_id' => $canceledBookingStatus->status_id,
        ]);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('user_notifications', 2);

        $completedTrip = $this->createTrip($driver, $completed->status_id, now()->subDay(), $damascus, $homs);

        $this->postJson('/api/v1/driver/trips/'.$completedTrip->trip_id.'/cancel', [
            'reason' => 'يجب أن يفشل',
        ])->assertStatus(422);
    }

    private function createDriverUser(): User
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Driver Test',
            'phone' => '0999999900',
            'email' => 'driver@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($driverRole->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'personal_photo' => 'driver.jpg',
            'approval_status' => DriverProfile::APPROVAL_APPROVED,
        ]);

        $vehicle = Vehicle::create([
            'driver_id' => $driver->user_id,
            'car_type' => 'Kia',
            'seat_capacity' => 4,
            'mechanical_car' => 'mechanic.pdf',
            'insurance_image' => 'insurance.pdf',
            'ownership_document' => 'plate-1',
            'certified_agency' => '2023',
        ]);

        VehicleImage::create([
            'vehicle_id' => $vehicle->id,
            'image_url' => 'vehicle.jpg',
        ]);

        return $driver;
    }

    private function createPassengerUser(string $email = 'passenger@example.com', string $phone = '0999999910'): User
    {
        return User::create([
            'full_name' => 'Passenger Test',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);
    }

    private function seedTripStatuses(): array
    {
        return [
            TripStatus::create([
                'status_key' => TripStatus::PENDING,
                'status_name' => 'قيد الانتظار',
                'description' => 'Pending',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => TripStatus::ACTIVE,
                'status_name' => 'نشطة',
                'description' => 'Active',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => TripStatus::COMPLETED,
                'status_name' => 'منجزة',
                'description' => 'Completed',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ]),
            TripStatus::create([
                'status_key' => TripStatus::CANCELED,
                'status_name' => 'ملغاة',
                'description' => 'Canceled',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ]),
        ];
    }

    private function seedBookingStatuses(): array
    {
        return [
            BookingStatus::create([
                'status_key' => 'pending',
                'status_name' => 'قيد الانتظار',
                'description' => 'Pending',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ]),
            BookingStatus::create([
                'status_key' => 'accepted',
                'status_name' => 'مقبول',
                'description' => 'Accepted',
                'is_final' => false,
                'display_order' => 2,
                'is_active' => true,
            ]),
            BookingStatus::create([
                'status_key' => 'canceled',
                'status_name' => 'ملغى',
                'description' => 'Canceled',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ]),
            BookingStatus::create([
                'status_key' => 'rejected',
                'status_name' => 'مرفوض',
                'description' => 'Rejected',
                'is_final' => true,
                'display_order' => 4,
                'is_active' => true,
            ]),
            BookingStatus::create([
                'status_key' => 'completed',
                'status_name' => 'منتهي',
                'description' => 'Completed',
                'is_final' => true,
                'display_order' => 5,
                'is_active' => true,
            ]),
        ];
    }

    private function seedAttendanceStatuses(): array
    {
        return [
            BookingAttendanceStatus::create([
                'status_key' => 'not_checked',
                'status_name' => 'غير مسجل',
                'description' => 'Not checked',
                'is_final' => false,
                'display_order' => 1,
                'is_active' => true,
            ]),
            BookingAttendanceStatus::create([
                'status_key' => 'present',
                'status_name' => 'حاضر',
                'description' => 'Present',
                'is_final' => true,
                'display_order' => 2,
                'is_active' => true,
            ]),
            BookingAttendanceStatus::create([
                'status_key' => 'absent',
                'status_name' => 'غائب',
                'description' => 'Absent',
                'is_final' => true,
                'display_order' => 3,
                'is_active' => true,
            ]),
        ];
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create([
                'name' => 'دمشق',
                'is_active' => true,
                'created_at' => now(),
            ]),
            Governorate::create([
                'name' => 'حمص',
                'is_active' => true,
                'created_at' => now(),
            ]),
        ];
    }

    private function createTrip(User $driver, int $statusId, $departureTime, Governorate $start, Governorate $end): Trip
    {
        $trip = Trip::create([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $start->governorate_id,
            'end_governorate_id' => $end->governorate_id,
            'departure_time' => $departureTime,
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 150.40,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 30000,
            'system_calculated_price' => 20000,
            'route_polyline' => 'encoded',
            'status_id' => $statusId,
            'created_at' => now(),
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'start',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'دمشق',
            'sequence_order' => 1,
        ]);

        TripPoint::create([
            'trip_id' => $trip->trip_id,
            'point_type' => 'end',
            'latitude' => 34.7308,
            'longitude' => 36.7090,
            'address' => 'حمص',
            'sequence_order' => 2,
        ]);

        return $trip->fresh(['points']);
    }

    private function createDriverBooking(
        Trip $trip,
        User $passenger,
        BookingStatus $status,
        BookingAttendanceStatus $attendanceStatus,
        string $code,
        $createdAt = null,
        string $paymentMethod = 'cash',
        float $amount = 25000
    ): Booking {
        $booking = Booking::create([
            'booking_code' => $code,
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => $paymentMethod,
            'total_amount' => $amount,
            'status_id' => $status->status_id,
            'attendance_status_id' => $attendanceStatus->status_id,
            'confirmed_at' => now(),
        ]);

        if ($createdAt !== null) {
            $booking->forceFill([
                'created_at' => $createdAt,
            ])->save();
        }

        BookingPickupPoint::create([
            'booking_id' => $booking->booking_id,
            'trip_point_id' => $trip->points()->first()->point_id,
            'governorate_id' => $trip->start_governorate_id,
            'point_name' => 'نقطة الانطلاق',
            'address' => 'دمشق',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'meeting_time' => now()->addMinutes(30),
            'is_new' => false,
        ]);

        return $booking;
    }
}
