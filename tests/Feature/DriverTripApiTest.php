<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttendanceStatus;
use App\Models\BookingPickupPoint;
use App\Models\BookingStatus;
use App\Models\DriverProfile;
use App\Models\CommissionRate;
use App\Models\Governorate;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripLiveLocation;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleImage;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\GovernorateResolverService;
use App\Services\RouteService;
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
            ->assertJsonPath('data.attendance.items.0.passenger_name', $passenger->full_name)
            ->assertJsonPath('data.attendance.items.0.passenger_image', 'storage/passengers/profile-photo.jpg')
            ->assertJsonPath('data.attendance.items.0.passenger_rating', 5);
    }

    public function test_driver_can_start_trip_and_notify_passengers(): void
    {
        $driver = $this->createDriverUser();
        [$pending, $active] = $this->seedTripStatuses();
        [, $acceptedStatus, $canceledStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, $pending->status_id, now()->subMinutes(5), $damascus, $homs);
        $passenger = $this->createPassengerUser('start-trip@example.com', '0999999970');
        $canceledPassenger = $this->createPassengerUser('start-trip-canceled@example.com', '0999999971');

        $this->createDriverBooking($trip, $passenger, $acceptedStatus, $notCheckedStatus, 'DRV-START-1', now()->subMinutes(15));
        $this->createDriverBooking($trip, $canceledPassenger, $canceledStatus, $notCheckedStatus, 'DRV-START-2', now()->subMinutes(10));

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/start', [
            'notes' => 'انطلقت الرحلة الآن',
        ])
            ->assertOk()
            ->assertJsonPath('data.classification.key', 'current')
            ->assertJsonPath('data.trip_details.start_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/start');

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => $active->status_id,
        ]);

        $notificationId = Notification::query()
            ->where('notification_type', 'trip_started')
            ->where('reference_id', $trip->trip_id)
            ->value('notification_id');

        $this->assertNotNull($notificationId);

        $this->assertDatabaseHas('user_notifications', [
            'notification_id' => $notificationId,
            'user_id' => $passenger->user_id,
            'is_sent' => true,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'notification_id' => $notificationId,
            'user_id' => $canceledPassenger->user_id,
        ]);
    }

    public function test_driver_can_store_live_locations_and_view_trip_tracking(): void
    {
        $driver = $this->createDriverUser();
        [$pending] = $this->seedTripStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, $pending->status_id, now()->subMinutes(5), $damascus, $homs);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/start', [
            'notes' => 'تم بدء الرحلة للتتبع اللحظي',
        ])->assertOk();

        $firstRecordedAt = now()->subSeconds(30);
        $secondRecordedAt = now()->subSeconds(5);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/location', [
            'latitude' => 33.6001000,
            'longitude' => 36.3001000,
            'speed_kmh' => 48.5,
            'heading' => 90,
            'accuracy_meters' => 7.3,
            'recorded_at' => $firstRecordedAt->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.tracking.is_tracking_active', true)
            ->assertJsonPath('data.tracking.history.count', 1);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/location', [
            'latitude' => 33.7002000,
            'longitude' => 36.4002000,
            'speed_kmh' => 52.1,
            'heading' => 110,
            'accuracy_meters' => 6.1,
            'recorded_at' => $secondRecordedAt->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.tracking.history.count', 2)
            ->assertJsonPath('data.tracking.last_position.latitude', 33.7002)
            ->assertJsonPath('data.tracking.location_update_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/location');

        $this->assertSame(2, TripLiveLocation::query()->where('trip_id', $trip->trip_id)->count());

        $freshTrip = $trip->fresh();
        $this->assertTrue((bool) $freshTrip->is_tracking_active);
        $this->assertEqualsWithDelta(33.7002, (float) $freshTrip->last_latitude, 0.000001);
        $this->assertEqualsWithDelta(36.4002, (float) $freshTrip->last_longitude, 0.000001);

        $this->getJson('/api/v1/driver/trips/'.$trip->trip_id)
            ->assertOk()
            ->assertJsonPath('data.trip_details.tracking_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/tracking')
            ->assertJsonPath('data.trip_details.location_update_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/location');

        $this->getJson('/api/v1/driver/trips/'.$trip->trip_id.'/tracking?history_limit=10')
            ->assertOk()
            ->assertJsonPath('data.tracking.history.count', 2)
            ->assertJsonPath('data.tracking.last_position.longitude', 36.4002)
            ->assertJsonPath('data.tracking.details_endpoint', '/api/v1/driver/trips/'.$trip->trip_id.'/tracking');
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
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.passenger_image', 'storage/passengers/profile-photo.jpg')
            ->assertJsonPath('data.items.0.passenger_rating', 5);

        $this->getJson('/api/v1/driver/bookings/'.$secondBooking->booking_id)
            ->assertOk()
            ->assertJsonPath('data.passenger.phone', $passengerTwo->phone)
            ->assertJsonPath('data.passenger.image', 'storage/passengers/profile-photo.jpg')
            ->assertJsonPath('data.passenger.rating', 5)
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
        $passenger->wallet()->update(['balance' => 12000]);
        $driver->wallet()->update(['balance' => 12000]);

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
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->user_id,
            'balance' => 24000,
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 0,
        ]);
        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $passenger->user_id,
            'related_booking_id' => $booking->booking_id,
            'receipt_type' => 'booking_rejection_refund',
        ]);
        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $driver->user_id,
            'related_booking_id' => $booking->booking_id,
            'receipt_type' => 'booking_rejection_reversal',
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
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->user_id,
            'balance' => 12000,
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 12000,
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

    public function test_driver_can_mark_booking_present_and_raise_passenger_rating(): void
    {
        $driver = $this->createDriverUser();
        [, $active] = $this->seedTripStatuses();
        [, $acceptedStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus, $presentStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $trip = $this->createTrip($driver, $active->status_id, now()->subMinutes(10), $damascus, $homs);
        $passenger = $this->createPassengerUser('present-status@example.com', '0999999961');
        $passenger->update(['rating' => 4.5]);

        $booking = $this->createDriverBooking(
            $trip,
            $passenger,
            $acceptedStatus,
            $notCheckedStatus,
            'DRV-4002',
            now()->subHour(),
            'cash',
            9000
        );

        Sanctum::actingAs($driver);

        $this->patchJson('/api/v1/driver/bookings/'.$booking->booking_id.'/attendance', [
            'attendance_status' => 'present',
            'notes' => 'حضر إلى نقطة الالتقاء',
        ])
            ->assertOk()
            ->assertJsonPath('data.booking.attendance_status.key', 'present');

        $this->assertDatabaseHas('booking_attendances', [
            'booking_id' => $booking->booking_id,
            'status_id' => $presentStatus->status_id,
            'penalty_amount' => 0,
            'rating_penalty' => 0,
        ]);

        $this->assertSame('4.60', $passenger->fresh()->rating);

        $this->patchJson('/api/v1/driver/bookings/'.$booking->booking_id.'/attendance', [
            'attendance_status' => 'absent',
            'notes' => 'محاولة تعديل غير مسموحة',
        ])->assertStatus(422);
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

        $electronicPassenger = $passengerTwo;
        $electronicPassenger->wallet()->update(['balance' => 0]);
        $driver->wallet()->update(['balance' => 18000]);

        Booking::create([
            'booking_code' => 'DRV-2002',
            'trip_id' => $trip->trip_id,
            'passenger_id' => $electronicPassenger->user_id,
            'booking_type' => 'shared',
            'seats_reserved' => 1,
            'payment_method' => 'electronic',
            'total_amount' => 18000,
            'status_id' => $acceptedBookingStatus->status_id,
        ]);

        Payment::create([
            'booking_id' => Booking::query()->where('booking_code', 'DRV-2002')->value('booking_id'),
            'wallet_id' => $electronicPassenger->wallet->wallet_id,
            'payment_method' => 'electronic',
            'amount' => 18000,
            'payment_status' => 'paid',
            'transaction_reference' => 'DRV-CANCEL-REF',
            'paid_at' => now(),
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
        $this->assertDatabaseHas('wallets', [
            'user_id' => $electronicPassenger->user_id,
            'balance' => 18000,
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 0,
        ]);
        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $electronicPassenger->user_id,
            'receipt_type' => 'trip_cancellation_refund',
        ]);
        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $driver->user_id,
            'receipt_type' => 'trip_cancellation_reversal',
        ]);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('user_notifications', 2);

        $completedTrip = $this->createTrip($driver, $completed->status_id, now()->subDay(), $damascus, $homs);

        $this->postJson('/api/v1/driver/trips/'.$completedTrip->trip_id.'/cancel', [
            'reason' => 'يجب أن يفشل',
        ])->assertStatus(422);
    }

    public function test_completed_trip_commission_counts_electronic_absent_only_and_excludes_cash_absent(): void
    {
        $driver = $this->createDriverUser();
        [, $active, $completedStatus] = $this->seedTripStatuses();
        [, $acceptedStatus, , , $completedBookingStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus, $presentStatus, $absentStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $rate = CommissionRate::create([
            'percentage' => 10,
            'previous_percentage' => 5,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
            'is_active' => true,
        ]);

        $trip = $this->createTrip($driver, $active->status_id, now()->subHours(2), $damascus, $homs);
        $trip->update([
            'commission_rate_id' => $rate->commission_rate_id,
            'commission_percentage' => 10,
            'is_tracking_active' => true,
            'tracking_started_at' => now()->subHours(2),
            'last_latitude' => 34.7308,
            'last_longitude' => 36.7090,
            'last_location_at' => now()->subMinute(),
        ]);

        $driver->wallet()->update(['balance' => 5000]);

        $electronicPassenger = $this->createPassengerUser('electronic-absent@example.com', '0999999805');
        $cashPassenger = $this->createPassengerUser('cash-absent@example.com', '0999999806');
        $presentPassenger = $this->createPassengerUser('present-passenger@example.com', '0999999807');

        $electronicBooking = $this->createDriverBooking($trip, $electronicPassenger, $acceptedStatus, $absentStatus, 'DRV-6001', now()->subMinutes(40), 'electronic', 10000);
        $cashAbsentBooking = $this->createDriverBooking($trip, $cashPassenger, $acceptedStatus, $absentStatus, 'DRV-6002', now()->subMinutes(30), 'cash', 10000);
        $presentBooking = $this->createDriverBooking($trip, $presentPassenger, $acceptedStatus, $presentStatus, 'DRV-6003', now()->subMinutes(20), 'cash', 10000);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/complete', [
            'notes' => 'احتساب الإيراد الفعلي مع غائب إلكتروني',
        ])
            ->assertOk()
            ->assertJsonPath('data.classification.key', 'completed')
            ->assertJsonPath('data.trip_details.commission.gross_revenue_amount', 20000)
            ->assertJsonPath('data.trip_details.commission.commission_amount', 2000)
            ->assertJsonPath('data.trip_details.commission.net_revenue_amount', 18000);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => $completedStatus->status_id,
            'gross_revenue_amount' => 20000,
            'commission_amount' => 2000,
            'net_revenue_amount' => 18000,
            'completion_reason' => 'driver_near_destination_live_tracking',
        ]);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $electronicBooking->booking_id,
            'status_id' => $completedBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $cashAbsentBooking->booking_id,
            'status_id' => $completedBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $presentBooking->booking_id,
            'status_id' => $completedBookingStatus->status_id,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 3000,
        ]);
    }

    public function test_driver_can_view_wallet_with_last_one_hundred_transaction_cards(): void
    {
        $driver = $this->createDriverUser();
        $adminRole = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);
        $admin = User::create([
            'full_name' => 'Wallet Admin',
            'phone' => '0999999998',
            'email' => 'wallet-admin@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $admin->roles()->attach($adminRole->id);

        $wallet = $driver->wallet()->first();
        $wallet->update(['balance' => 25000]);

        for ($i = 0; $i < 105; $i++) {
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->wallet_id,
                'amount' => 12000 + $i,
                'transaction_type' => $i % 2 === 0 ? 'credit' : 'adjustment',
                'status' => 'completed',
                'transaction_reference' => 'DRV-WALLET-'.$i,
                'description' => 'حجز إلكتروني للرحلة '.($i + 1),
                'balance_before' => 1000,
                'balance_after' => 2000,
                'performed_by' => $admin->user_id,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);

            $receipt = Receipt::create([
                'receipt_number' => 'RCT-DRV-'.$i,
                'owner_user_id' => $driver->user_id,
                'wallet_id' => $wallet->wallet_id,
                'related_wallet_transaction_id' => $transaction->transaction_id,
                'receipt_type' => $i % 2 === 0 ? 'booking_income' : 'trip_cancellation_reversal',
                'direction' => $i % 2 === 0 ? 'credit' : 'debit',
                'status' => 'paid',
                'amount' => 12000 + $i,
                'counterparty_user_id' => $admin->user_id,
                'counterparty_name' => $admin->full_name,
                'reason' => 'حجز إلكتروني للرحلة '.($i + 1),
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);

            $transaction->update([
                'related_receipt_id' => $receipt->receipt_id,
            ]);
        }

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.current_balance', 25000)
            ->assertJsonCount(100, 'data.wallet.recent_transactions')
            ->assertJsonPath('data.wallet.recent_transactions.0.title', 'دخل حجز إلكتروني')
            ->assertJsonPath('data.wallet.recent_transactions.0.formatted_amount', '+12,104')
            ->assertJsonPath('data.wallet.recent_transactions.0.status_label', 'مكتملة')
            ->assertJsonPath('data.wallet.recent_transactions.0.actor_name', 'Wallet Admin')
            ->assertJsonPath('data.wallet.recent_transactions.0.reason', 'حجز إلكتروني للرحلة 105')
            ->assertJsonPath('data.wallet.recent_transactions.0.details_endpoint', '/api/v1/driver/wallet/transactions/105');
    }

    public function test_driver_cannot_create_trip_if_wallet_does_not_cover_max_commission(): void
    {
        $driver = $this->createDriverUser();
        [$damascus] = $this->createGovernorates();
        $this->fakeGovernorateResolver($damascus);

        CommissionRate::create([
            'percentage' => 20,
            'effective_from' => now()->subHour(),
            'is_active' => true,
            'created_by' => $driver->user_id,
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips', [
            'departure_time' => now()->addHours(2)->toIso8601String(),
            'total_seats' => 4,
            'allow_shared' => true,
            'allow_private' => false,
            'shared_price' => 3000,
            'points' => [
                [
                    'point_type' => 'start',
                    'latitude' => 33.5138,
                    'longitude' => 36.2765,
                ],
                [
                    'point_type' => 'end',
                    'latitude' => 34.7308,
                    'longitude' => 36.7090,
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_balance');
    }

    public function test_trip_preview_uses_vehicle_category_price_per_km(): void
    {
        $driver = $this->createDriverUser();
        $driver->wallet()->update(['balance' => 5000]);

        $category = VehicleCategory::where('name', 'تكسي صفراء')->firstOrFail();

        Vehicle::where('driver_id', $driver->user_id)->update([
            'vehicle_category_id' => $category->category_id,
        ]);

        [$damascus] = $this->createGovernorates();
        $this->fakeGovernorateResolver($damascus);
        $this->fakeRouteService(10);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips/preview', [
            'departure_time' => now()->addHours(2)->toIso8601String(),
            'total_seats' => 4,
            'allow_shared' => true,
            'allow_private' => true,
            'points' => [
                [
                    'point_type' => 'start',
                    'latitude' => 33.5138,
                    'longitude' => 36.2765,
                ],
                [
                    'point_type' => 'end',
                    'latitude' => 34.7308,
                    'longitude' => 36.7090,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.estimated_distance_km', 10)
            ->assertJsonPath('data.system_calculated_price', 945)
            ->assertJsonMissingPath('data.vehicle_category');
    }

    public function test_driver_can_complete_trip_and_commission_is_deducted_from_gross_revenue(): void
    {
        $driver = $this->createDriverUser();
        $driver->wallet()->update(['balance' => 5000]);

        [, $active] = $this->seedTripStatuses();
        [$pendingStatus, $acceptedStatus, $canceledStatus, , $completedBookingStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();

        $commissionRate = CommissionRate::create([
            'percentage' => 10,
            'effective_from' => now()->subHour(),
            'is_active' => true,
            'created_by' => $driver->user_id,
        ]);

        $trip = $this->createTrip($driver, $active->status_id, now()->subHours(2), $damascus, $homs);
        $trip->update([
            'commission_rate_id' => $commissionRate->commission_rate_id,
            'commission_percentage' => 10,
            'max_commission_amount' => 4000,
        ]);

        $passengerOne = $this->createPassengerUser('complete1@example.com', '0999999801');
        $passengerTwo = $this->createPassengerUser('complete2@example.com', '0999999802');
        $passengerThree = $this->createPassengerUser('complete3@example.com', '0999999803');
        $passengerCanceled = $this->createPassengerUser('complete4@example.com', '0999999804');

        $firstBooking = $this->createDriverBooking($trip, $passengerOne, $acceptedStatus, $notCheckedStatus, 'DRV-5001', now()->subMinutes(50), 'cash', 10000);
        $secondBooking = $this->createDriverBooking($trip, $passengerTwo, $acceptedStatus, $notCheckedStatus, 'DRV-5002', now()->subMinutes(40), 'cash', 10000);
        $thirdBooking = $this->createDriverBooking($trip, $passengerThree, $acceptedStatus, $notCheckedStatus, 'DRV-5003', now()->subMinutes(30), 'cash', 10000);
        $canceledBooking = $this->createDriverBooking($trip, $passengerCanceled, $canceledStatus, $notCheckedStatus, 'DRV-5004', now()->subMinutes(20), 'cash', 10000);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/trips/'.$trip->trip_id.'/complete', [
            'notes' => 'تم الوصول وإنهاء الرحلة',
        ])
            ->assertOk()
            ->assertJsonPath('data.classification.key', 'completed')
            ->assertJsonPath('data.trip_details.commission.gross_revenue_amount', 30000)
            ->assertJsonPath('data.trip_details.commission.commission_amount', 3000)
            ->assertJsonPath('data.trip_details.commission.net_revenue_amount', 27000)
            ->assertJsonPath('data.trip_details.completion.mode', 'driver');

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => TripStatus::where('status_key', TripStatus::COMPLETED)->value('status_id'),
            'gross_revenue_amount' => 30000,
            'commission_amount' => 3000,
            'net_revenue_amount' => 27000,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 2000,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $driver->wallet->wallet_id,
            'transaction_type' => 'commission',
            'amount' => 3000,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('receipts', [
            'owner_user_id' => $driver->user_id,
            'related_trip_id' => $trip->trip_id,
            'receipt_type' => 'driver_trip_settlement',
            'gross_amount' => 30000,
            'commission_amount' => 3000,
            'net_amount' => 27000,
        ]);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $firstBooking->booking_id,
            'status_id' => $completedBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $secondBooking->booking_id,
            'status_id' => $completedBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $thirdBooking->booking_id,
            'status_id' => $completedBookingStatus->status_id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $canceledBooking->booking_id,
            'status_id' => $canceledStatus->status_id,
        ]);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $firstBooking->booking_id,
            'payment_status' => 'paid',
        ]);
    }

    public function test_system_can_auto_complete_trip_and_set_auto_completed_status(): void
    {
        $driver = $this->createDriverUser();
        $driver->wallet()->update(['balance' => 5000]);

        [, $active] = $this->seedTripStatuses();
        [, $acceptedStatus] = $this->seedBookingStatuses();
        [$notCheckedStatus] = $this->seedAttendanceStatuses();
        [$damascus, $homs] = $this->createGovernorates();
        $autoCompletedStatus = TripStatus::updateOrCreate(['status_key' => TripStatus::AUTO_COMPLETED], [
            'status_key' => TripStatus::AUTO_COMPLETED,
            'status_name' => 'منتهية تلقائياً',
            'description' => 'Auto completed',
            'is_final' => true,
            'display_order' => 5,
            'is_active' => true,
        ]);

        $commissionRate = CommissionRate::create([
            'percentage' => 10,
            'effective_from' => now()->subDay(),
            'is_active' => true,
            'created_by' => $driver->user_id,
        ]);

        $trip = $this->createTrip($driver, $active->status_id, now()->subHours(4), $damascus, $homs);
        $trip->update([
            'commission_rate_id' => $commissionRate->commission_rate_id,
            'commission_percentage' => 10,
            'max_commission_amount' => 4000,
        ]);

        $passenger = $this->createPassengerUser('auto-complete-passenger@example.com', '0999999808');
        $booking = $this->createDriverBooking($trip, $passenger, $acceptedStatus, $notCheckedStatus, 'DRV-7001', now()->subHours(3), 'cash', 10000);

        $this->artisan('trips:auto-complete')
            ->expectsOutputToContain('Auto-completed trips: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('trips', [
            'trip_id' => $trip->trip_id,
            'status_id' => $autoCompletedStatus->status_id,
            'gross_revenue_amount' => 10000,
            'commission_amount' => 1000,
            'net_revenue_amount' => 9000,
            'completion_mode' => 'system',
            'completion_reason' => 'system_timeout_no_tracking_time_fallback',
        ]);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $booking->booking_id,
            'status_id' => BookingStatus::where('status_key', 'completed')->value('status_id'),
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'trip_auto_completed',
            'reference_id' => $trip->trip_id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'driver_trip_auto_completed',
            'reference_id' => $trip->trip_id,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 4000,
        ]);
    }

    public function test_driver_can_list_all_wallet_transactions_as_cards(): void
    {
        [$driver, $admin] = $this->seedDriverWalletTransactions();

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/wallet/transactions?search=Wallet Admin&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.data.0.title', 'دخل حجز إلكتروني')
            ->assertJsonPath('data.data.0.formatted_amount', '+12,004')
            ->assertJsonPath('data.data.0.actor_name', $admin->full_name)
            ->assertJsonPath('data.data.0.details_endpoint', '/api/v1/driver/wallet/transactions/5')
            ->assertJsonPath('data.total', 5);
    }

    public function test_driver_can_view_wallet_transaction_details(): void
    {
        [$driver, $admin, $transaction, $receipt] = $this->seedDriverWalletTransactions(true);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/wallet/transactions/'.$transaction->transaction_id)
            ->assertOk()
            ->assertJsonPath('data.card.transaction_id', $transaction->transaction_id)
            ->assertJsonPath('data.card.actor_name', $admin->full_name)
            ->assertJsonPath('data.card.details_endpoint', '/api/v1/driver/wallet/transactions/'.$transaction->transaction_id)
            ->assertJsonPath('data.details.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.details.amount', 12004)
            ->assertJsonPath('data.details.trip.trip_id', $receipt->trip->trip_id);
    }

    private function seedDriverWalletTransactions(bool $withTripDetails = false): array
    {
        $driver = $this->createDriverUser();
        $adminRole = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);
        $admin = User::create([
            'full_name' => 'Wallet Admin',
            'phone' => '0999999998',
            'email' => $withTripDetails ? 'wallet-admin-details@example.com' : 'wallet-admin-list@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $admin->roles()->attach($adminRole->id);

        $wallet = $driver->wallet()->first();
        $wallet->update(['balance' => 25000]);

        $trip = null;

        if ($withTripDetails) {
            [$pending] = $this->seedTripStatuses();
            [$damascus, $homs] = $this->createGovernorates();
            $trip = $this->createTrip($driver, $pending->status_id, now()->addHour(), $damascus, $homs);
        }

        $lastTransaction = null;
        $lastReceipt = null;

        for ($i = 0; $i < 5; $i++) {
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->wallet_id,
                'amount' => 12000 + $i,
                'transaction_type' => 'credit',
                'status' => 'completed',
                'transaction_reference' => 'DRV-WALLET-'.$i,
                'description' => 'حجز إلكتروني للرحلة '.($i + 1),
                'balance_before' => 1000,
                'balance_after' => 2000,
                'performed_by' => $admin->user_id,
                'created_at' => now()->subMinutes(4 - $i),
                'updated_at' => now()->subMinutes(4 - $i),
            ]);

            $receipt = Receipt::create([
                'receipt_number' => 'RCT-DRV-'.$i,
                'owner_user_id' => $driver->user_id,
                'wallet_id' => $wallet->wallet_id,
                'related_wallet_transaction_id' => $transaction->transaction_id,
                'related_trip_id' => $trip?->trip_id,
                'receipt_type' => 'booking_income',
                'direction' => 'credit',
                'status' => 'paid',
                'amount' => 12000 + $i,
                'counterparty_user_id' => $admin->user_id,
                'counterparty_name' => $admin->full_name,
                'reason' => 'حجز إلكتروني للرحلة '.($i + 1),
                'created_at' => now()->subMinutes(4 - $i),
                'updated_at' => now()->subMinutes(4 - $i),
            ]);

            $transaction->update([
                'related_receipt_id' => $receipt->receipt_id,
            ]);

            $lastTransaction = $transaction->fresh();
            $lastReceipt = $receipt->fresh(['trip']);
        }

        if ($withTripDetails) {
            return [$driver, $admin, $lastTransaction, $lastReceipt];
        }

        return [$driver, $admin];
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

        Wallet::create([
            'user_id' => $driver->user_id,
            'balance' => 0,
        ]);

        return $driver;
    }

    private function createPassengerUser(string $email = 'passenger@example.com', string $phone = '0999999910'): User
    {
        $passengerRole = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => 'Passenger Test',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
            'profile_photo' => 'storage/passengers/profile-photo.jpg',
        ]);

        $passenger->roles()->attach($passengerRole->id);

        Wallet::create([
            'user_id' => $passenger->user_id,
            'balance' => 0,
        ]);

        return $passenger;
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

        Payment::create([
            'booking_id' => $booking->booking_id,
            'wallet_id' => $paymentMethod === 'electronic' ? $passenger->wallet?->wallet_id : null,
            'payment_method' => $paymentMethod,
            'amount' => $amount,
            'payment_status' => $paymentMethod === 'electronic' ? 'paid' : 'pending',
            'transaction_reference' => $paymentMethod === 'electronic' ? 'PAY-'.$code : null,
            'paid_at' => $paymentMethod === 'electronic' ? now() : null,
        ]);

        return $booking;
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

            public function resolveTripGovernorates(array $orderedPoints): array
            {
                return [
                    'start_governorate_id' => (int) $this->governorate->governorate_id,
                    'end_governorate_id' => (int) $this->governorate->governorate_id,
                    'start_governorate' => $this->governorate,
                    'end_governorate' => $this->governorate,
                ];
            }
        };

        $this->app->instance(GovernorateResolverService::class, $resolver);
    }

    private function fakeRouteService(float $distanceKm): void
    {
        $routeService = new class($distanceKm) extends RouteService {
            public function __construct(private float $distanceKm)
            {
            }

            public function buildRoute(array $points): array
            {
                $orderedPoints = collect($points)
                    ->values()
                    ->map(function (array $point, int $index) {
                        $point['sequence_order'] = $index + 1;
                        $point['eta_offset_seconds'] = $index * 600;

                        return $point;
                    })
                    ->all();

                return [
                    'ordered_points' => $orderedPoints,
                    'estimated_distance_km' => $this->distanceKm,
                    'estimated_duration_minutes' => 20,
                    'polyline' => 'encoded-test-polyline',
                ];
            }
        };

        $this->app->instance(RouteService::class, $routeService);
    }
}
