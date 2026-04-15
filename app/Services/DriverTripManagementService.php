<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCancellation;
use App\Models\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\DriverProfile;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DriverTripManagementService
{
    public function listTrips(User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        $trips = $this->baseDriverTripsQuery($driverProfile)
            ->orderBy('departure_time')
            ->get()
            ->map(fn (Trip $trip) => $this->transformCardTrip($trip));
            
        return [
            'items' => $trips->values(),
            'counts' => [
                'all' => $trips->count(),
                'pending' => $trips->where('classification.key', 'pending')->count(),
                'current' => $trips->where('classification.key', 'current')->count(),
                'completed' => $trips->where('classification.key', 'completed')->count(),
                'canceled' => $trips->where('classification.key', 'canceled')->count(),
            ],
        ];
    }

    public function listPendingTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'pending'),
        ];
    }

    public function listCurrentTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'current'),
        ];
    }

    public function listCompletedTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'completed'),
        ];
    }

    public function listCanceledTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'canceled'),
        ];
    }

    public function showTripDetails(int $tripId, User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.passenger',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.pickupPoint.governorate',
                'bookings.pickupPoint.tripPoint',
                'bookings.payments',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        return $this->transformTripDetails($trip);
    }

    public function showTripAttendance(int $tripId, User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.passenger',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.pickupPoint',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        return [
            'trip_id' => $trip->trip_id,
            'attendance' => [
                'items' => $trip->bookings->map(function (Booking $booking) {
                    return [
                        'booking_id' => $booking->booking_id,
                        'booking_code' => $booking->booking_code,
                        'passenger_name' => $booking->passenger?->full_name,
                        'passenger_phone' => $booking->passenger?->phone,
                        'pickup_point' => $booking->pickupPoint?->point_name ?? $booking->pickupPoint?->address,
                        'booking_status' => $booking->status?->status_name,
                        'attendance_status' => $booking->attendanceStatus?->status_name,
                        'attendance_status_key' => $booking->attendanceStatus?->status_key,
                    ];
                })->values(),
            ],
        ];
    }

    public function cancelTrip(int $tripId, User $actor, ?string $reason): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.status',
                'bookings.passenger',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        $tripStatusKey = $trip->status?->status_key;
        if (in_array($tripStatusKey, [TripStatus::COMPLETED, TripStatus::CANCELED], true)) {
            throw new RuntimeException('لا يمكن إلغاء الرحلات المنجزة أو الملغاة مسبقاً.', 422);
        }

        $canceledTripStatus = $this->resolveTripStatus(TripStatus::CANCELED);
        $canceledBookingStatus = $this->resolveBookingStatus('canceled');
        $reasonText = $reason ?: 'تم إلغاء الرحلة من قبل السائق.';

        DB::transaction(function () use ($trip, $actor, $canceledTripStatus, $canceledBookingStatus, $reasonText) {
            $trip->forceFill([
                'status_id' => $canceledTripStatus->status_id,
            ])->save();

            foreach ($trip->bookings as $booking) {
                $currentStatusKey = $booking->status?->status_key;

                if (in_array($currentStatusKey, ['canceled', 'completed'], true)) {
                    continue;
                }

                $fromStatusId = $booking->status_id;

                $booking->forceFill([
                    'status_id' => $canceledBookingStatus->status_id,
                    'canceled_at' => now(),
                ])->save();

                BookingStatusLog::create([
                    'booking_id' => $booking->booking_id,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $canceledBookingStatus->status_id,
                    'changed_by' => $actor->user_id,
                    'reason' => $reasonText,
                    'changed_at' => now(),
                ]);

                BookingCancellation::firstOrCreate(
                    ['booking_id' => $booking->booking_id],
                    [
                        'canceled_by' => $actor->user_id,
                        'reason' => $reasonText,
                        'cancellation_time' => now(),
                        'hours_before_departure' => $this->hoursBeforeDeparture($trip),
                        'penalty_percentage' => 0,
                        'penalty_amount' => 0,
                        'wallet_refund_amount' => 0,
                        'rating_penalty' => 0,
                    ]
                );

                if ($booking->passenger_id) {
                    $notification = Notification::create([
                        'title' => 'إلغاء الرحلة',
                        'body' => "تم إلغاء الرحلة رقم {$trip->trip_id} من قبل السائق. السبب: {$reasonText}",
                        'notification_type' => 'trip_canceled_by_driver',
                        'reference_type' => 'trip',
                        'reference_id' => $trip->trip_id,
                        'created_by' => $actor->user_id,
                        'target_role' => Role::ROLE_PASSENGER,
                    ]);

                    UserNotification::firstOrCreate(
                        [
                            'notification_id' => $notification->notification_id,
                            'user_id' => $booking->passenger_id,
                        ],
                        [
                            'is_sent' => true,
                            'sent_at' => now(),
                        ]
                    );
                }
            }
        });

        return $this->showTripDetails($tripId, $actor);
    }

    private function filteredTrips(User $actor, string $classification): Collection
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        return $this->baseDriverTripsQuery($driverProfile)
            ->orderBy('departure_time')
            ->get()
            ->map(fn (Trip $trip) => $this->transformCardTrip($trip))
            ->filter(fn (array $trip) => data_get($trip, 'classification.key') === $classification)
            ->values();
    }

    private function resolveDriverProfile(User $actor): DriverProfile
    {
        $actor->loadMissing('driverProfile');

        if (! $actor->driverProfile) {
            throw new RuntimeException('المستخدم الحالي لا يملك ملف سائق.', 403);
        }

        return $actor->driverProfile;
    }

    private function baseDriverTripsQuery(DriverProfile $driverProfile): Builder
    {
        return Trip::query()
            ->where('driver_id', $driverProfile->user_id)
            ->with([
                'status',
                'startGovernorate',
                'endGovernorate',
                'points',
                'driver.user',
                'driver.vehicles.images',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.passenger',
                'bookings.pickupPoint',
            ])
            ->withCount('bookings');
    }

    private function transformCardTrip(Trip $trip): array
    {
        $classification = $this->classifyTrip($trip);
        $vehicle = $trip->driver?->vehicles->first();
        $expectedArrival = $this->expectedArrival($trip);

        return [
            'trip_id' => $trip->trip_id,
            'classification' => $classification,
            
            'trip_type' => $this->tripType($trip),
            'card' => [
                'vehicle_image' => $vehicle?->images->first()?->image_url,
                'departure_location' => $trip->startGovernorate?->name,
                'arrival_location' => $trip->endGovernorate?->name,
                'departure_time' => optional($trip->departure_time)->toIso8601String(),
                'expected_arrival_time' => $expectedArrival?->toIso8601String(),
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'available_seats' => (int) $trip->available_seats,
                'details_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}",
            ],
        ];
    }

    private function transformTripDetails(Trip $trip): array
    {
        $expectedArrival = $this->expectedArrival($trip);
        $points = $trip->points->sortBy('sequence_order')->values();
        $vehicle = $trip->driver?->vehicles->first();

        return [
            'trip_id' => $trip->trip_id,
            'classification' => $this->classifyTrip($trip),
            
            'trip_details' => [
                'departure_time' => optional($trip->departure_time)->toIso8601String(),
                'expected_arrival_time' => $expectedArrival?->toIso8601String(),
                'departure_location' => $trip->startGovernorate?->name,
                'arrival_location' => $trip->endGovernorate?->name,
                'route_polyline' => $trip->route_polyline,
                'trip_type' => $this->tripType($trip),
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'remaining_seats' => (int) $trip->available_seats,
                'vehicle' => [
                    'type' => $vehicle?->car_type,
                    'model' => $vehicle?->certified_agency,
                    'image' => $vehicle?->images->first()?->image_url,
                ],
                'points' => $points->map(function ($point) {
                    return [
                        'point_id' => $point->point_id,
                        'type' => $point->point_type,
                        'address' => $point->address,
                        'note' => $point->note,
                        'latitude' => (float) $point->latitude,
                        'longitude' => (float) $point->longitude,
                        'sequence_order' => (int) $point->sequence_order,
                        'expected_arrival_time' => optional($point->expected_arrival_time)->toIso8601String(),
                    ];
                })->values(),
                'bookings_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/bookings",
                'attendance_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/attendance",
                'cancel_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/cancel",
            ],
        ];
    }

    private function transformBookingDetails(Booking $booking): array
    {
        $payment = $booking->payments->sortByDesc('payment_id')->first();

        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'booking_type' => $booking->booking_type,
            'seats_reserved' => (int) $booking->seats_reserved,
            'passenger' => [
                'id' => $booking->passenger?->user_id,
                'full_name' => $booking->passenger?->full_name,
                'phone' => $booking->passenger?->phone,
                'rating' => $booking->passenger?->rating,
            ],
            'pickup_point' => [
                'point_name' => $booking->pickupPoint?->point_name,
                'address' => $booking->pickupPoint?->address,
                'meeting_time' => optional($booking->pickupPoint?->meeting_time)->toIso8601String(),
                'latitude' => $booking->pickupPoint?->latitude !== null ? (float) $booking->pickupPoint->latitude : null,
                'longitude' => $booking->pickupPoint?->longitude !== null ? (float) $booking->pickupPoint->longitude : null,
                'governorate' => $booking->pickupPoint?->governorate?->name,
            ],
            'payment' => [
                'method' => $payment?->payment_method ?? $booking->payment_method,
                'status' => $payment?->payment_status,
                'amount' => $payment?->amount !== null ? (float) $payment->amount : (float) $booking->total_amount,
            ],
            'status' => [
                'key' => $booking->status?->status_key,
                'name' => $booking->status?->status_name,
            ],
            'attendance' => [
                'key' => $booking->attendanceStatus?->status_key,
                'name' => $booking->attendanceStatus?->status_name,
            ],
            'notes' => $booking->notes,
        ];
    }

    private function classifyTrip(Trip $trip): array
    {
        $statusKey = $trip->status?->status_key;
        $departure = $trip->departure_time ? Carbon::parse($trip->departure_time) : null;

        if ($statusKey === TripStatus::CANCELED) {
            return ['key' => 'canceled', 'name' => 'ملغاة'];
        }

        if ($statusKey === TripStatus::COMPLETED) {
            return ['key' => 'completed', 'name' => 'منجزة'];
        }

        if ($statusKey === TripStatus::ACTIVE || ($statusKey === TripStatus::PENDING && $departure && now()->greaterThanOrEqualTo($departure))) {
            return ['key' => 'current', 'name' => 'حالية'];
        }

        return ['key' => 'pending', 'name' => 'قيد الانتظار'];
    }

    private function tripType(Trip $trip): string
    {
        if ($trip->allow_shared && $trip->allow_private) {
            return 'both';
        }

        if ($trip->allow_private) {
            return 'private';
        }

        return 'shared';
    }

    private function expectedArrival(Trip $trip): ?Carbon
    {
        if (! $trip->departure_time) {
            return null;
        }

        return Carbon::parse($trip->departure_time)->addMinutes((int) $trip->estimated_duration_minutes);
    }

    private function resolveTripStatus(string $statusKey): TripStatus
    {
        $status = TripStatus::query()
            ->where('status_key', $statusKey)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw new RuntimeException('حالة الرحلة المطلوبة غير موجودة.', 500);
        }

        return $status;
    }

    private function resolveBookingStatus(string $statusKey): BookingStatus
    {
        $status = BookingStatus::query()
            ->where('status_key', $statusKey)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw new RuntimeException('حالة الحجز المطلوبة غير موجودة.', 500);
        }

        return $status;
    }

    private function hoursBeforeDeparture(Trip $trip): float
    {
        if (! $trip->departure_time) {
            return 0;
        }

        return round(now()->floatDiffInHours(Carbon::parse($trip->departure_time), false), 2);
    }
}
