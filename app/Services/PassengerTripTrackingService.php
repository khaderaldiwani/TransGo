<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use RuntimeException;

class PassengerTripTrackingService
{
    public function __construct(
        private readonly TripTrackingService $tripTrackingService
    ) {
    }

    public function getTripTracking(int $tripId, User $actor, int $historyLimit = 100): array
    {
        $booking = Booking::query()
            ->with([
                'trip.status',
                'status',
                'trip.driver.user',
                'trip.startGovernorate',
                'trip.endGovernorate',
            ])
            ->where('trip_id', $tripId)
            ->where('passenger_id', $actor->user_id)
            ->latest('booking_id')
            ->first();

        if (! $booking || in_array($booking->status?->status_key, ['canceled', 'rejected'], true)) {
            throw new RuntimeException('لا يمكن الوصول إلى تتبع هذه الرحلة لأن الراكب غير مشترك بها.', 404);
        }

        $trip = $booking->trip;

        if (! $trip instanceof Trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة.', 404);
        }

        $trackingAvailable = $trip->status?->status_key === TripStatus::ACTIVE
            && (bool) $trip->is_tracking_active;

        if (! $trackingAvailable) {
            return [
                'trip_id' => $trip->trip_id,
                'booking_id' => $booking->booking_id,
                'tracking_available' => false,
                'tracking_enabled_after_start' => true,
                'tracking_endpoint' => "/api/v1/passenger/trips/{$trip->trip_id}/tracking",
                'status' => [
                    'key' => $trip->status?->status_key,
                    'name' => $trip->status?->status_name,
                ],
                'trip' => [
                    'departure_at' => \App\Support\ApiDateTime::toAppIso($trip->departure_time),
                    'from' => $trip->startGovernorate?->name,
                    'to' => $trip->endGovernorate?->name,
                ],
                'message' => 'يتم إتاحة تتبع السائق بعد بدء الرحلة فقط.',
            ];
        }

        $tracking = $this->tripTrackingService->getAdminTripTracking($trip->trip_id, $historyLimit);

        return [
            'trip_id' => $trip->trip_id,
            'booking_id' => $booking->booking_id,
            'tracking_available' => true,
            'tracking_enabled_after_start' => true,
            'tracking_endpoint' => "/api/v1/passenger/trips/{$trip->trip_id}/tracking",
            'trip' => [
                'departure_at' => data_get($tracking, 'trip.departure_at'),
                'actual_start_time' => data_get($tracking, 'trip.actual_start_time'),
                'from' => data_get($tracking, 'trip.from'),
                'to' => data_get($tracking, 'trip.to'),
                'route_polyline' => data_get($tracking, 'trip.route_polyline'),
            ],
            'driver' => [
                'id' => data_get($tracking, 'driver.id'),
                'full_name' => data_get($tracking, 'driver.full_name'),
            ],
            'tracking' => data_get($tracking, 'tracking'),
        ];
    }
}
