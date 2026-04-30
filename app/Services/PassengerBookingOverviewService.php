<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TripPoint;
use App\Models\User;
use RuntimeException;

class PassengerBookingOverviewService
{
    public function groupedByTrip(User $passenger): array
    {
        $bookings = Booking::query()
            ->with($this->relations())
            ->where('passenger_id', $passenger->user_id)
            ->get()
            ->sortBy(fn (Booking $booking) => optional($booking->trip?->departure_time)->timestamp ?? PHP_INT_MAX)
            ->values();

        $items = $bookings
            ->groupBy('trip_id')
            ->map(fn ($tripBookings) => $this->transformTripGroup($tripBookings->values()))
            ->values();

        return [
            'items' => $items,
            'total_trips' => $items->count(),
            'total_bookings' => $bookings->count(),
        ];
    }

    public function show(int $bookingId, User $passenger): array
    {
        $booking = Booking::query()
            ->with($this->relations())
            ->where('passenger_id', $passenger->user_id)
            ->find($bookingId);

        if (! $booking) {
            throw new RuntimeException('الحجز غير موجود.', 404);
        }

        return $this->transformDetails($booking);
    }

    public function listForTrip(int $tripId, User $passenger): array
    {
        $bookings = Booking::query()
            ->with($this->relations())
            ->where('passenger_id', $passenger->user_id)
            ->where('trip_id', $tripId)
            ->get()
            ->sortByDesc('created_at')
            ->values();

        if ($bookings->isEmpty()) {
            throw new RuntimeException('لا توجد حجوزات لهذا الراكب على هذه الرحلة.', 404);
        }

        return $this->transformTripBookingsOnly($bookings);
    }

    private function relations(): array
    {
        return [
            'status',
            'attendanceStatus',
            'pickupPoint.governorate',
            'pickupPoint.tripPoint',
            'trip.status',
            'trip.points',
            'trip.startGovernorate',
            'trip.endGovernorate',
            'trip.driver.user',
            'trip.driver.vehicles.images',
            'payments',
            'cancellation.canceller',
            'statusLogs.fromStatus',
            'statusLogs.toStatus',
            'statusLogs.actor',
        ];
    }

    private function transformTripGroup($tripBookings): array
    {
        /** @var Booking $firstBooking */
        $firstBooking = $tripBookings->first();
        $trip = $firstBooking->trip;
        $vehicle = $trip?->driver?->vehicles?->first();

        return [
            'trip_id' => $trip?->trip_id,
            'trip_status' => [
                'key' => $trip?->status?->status_key,
                'name' => $trip?->status?->status_name,
            ],
            'departure_time' => $trip?->departure_time?->toIso8601String(),
            'route' => [
                'from' => $trip?->startGovernorate?->name,
                'to' => $trip?->endGovernorate?->name,
            ],
            'driver' => [
                'id' => $trip?->driver_id,
                'full_name' => $trip?->driver?->user?->full_name,
                'image' => $trip?->driver?->personal_photo,
                'rating' => $trip?->driver?->user?->rating !== null ? (float) $trip->driver->user->rating : null,
            ],
            'vehicle' => [
                'type' => $vehicle?->car_type,
                'image' => $vehicle?->images?->first()?->image_url,
            ],
            'bookings_count' => $tripBookings->count(),
            'bookings' => $tripBookings
                ->sortByDesc('created_at')
                ->map(fn (Booking $booking) => $this->transformBookingSummary($booking))
                ->values(),
            'trip_details_endpoint' => $trip?->trip_id !== null ? "/api/v1/passenger/trips/{$trip->trip_id}" : null,
        ];
    }

    private function transformTripBookingsOnly($tripBookings): array
    {
        /** @var Booking $firstBooking */
        $firstBooking = $tripBookings->first();

        return [
            'trip_id' => $firstBooking->trip_id,
            'bookings_count' => $tripBookings->count(),
            'bookings' => $tripBookings
                ->sortByDesc('created_at')
                ->map(fn (Booking $booking) => $this->transformBookingSummary($booking))
                ->values(),
        ];
    }

    private function transformBookingSummary(Booking $booking): array
    {
        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'booking_type' => $booking->booking_type,
            'seats_reserved' => (int) $booking->seats_reserved,
            'payment_method' => $booking->payment_method,
            'total_amount' => $booking->total_amount !== null ? (float) $booking->total_amount : null,
            'status' => [
                'key' => $booking->status?->status_key,
                'name' => $booking->status?->status_name,
            ],
            'pickup' => $this->transformPickupPoint($booking),
            'created_at' => $booking->created_at?->toIso8601String(),
            'details_endpoint' => "/api/v1/passenger/bookings/{$booking->booking_id}",
        ];
    }

    private function transformDetails(Booking $booking): array
    {
        $payment = $booking->payments->sortByDesc('payment_id')->first();
        $latestRejectedLog = $booking->statusLogs
            ->sortByDesc('changed_at')
            ->first(fn ($log) => $log->toStatus?->status_key === 'rejected');

        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'booking' => [
                'booking_type' => $booking->booking_type,
                'created_at' => $booking->created_at?->toIso8601String(),
                'confirmed_at' => $booking->confirmed_at?->toIso8601String(),
                'seats_reserved' => (int) $booking->seats_reserved,
                'status' => [
                    'key' => $booking->status?->status_key,
                    'name' => $booking->status?->status_name,
                ],
                'attendance_status' => [
                    'key' => $booking->attendanceStatus?->status_key,
                    'name' => $booking->attendanceStatus?->status_name,
                ],
                'payment_method' => $payment?->payment_method ?? $booking->payment_method,
                'payment_status' => $payment?->payment_status,
                'amount' => $payment?->amount !== null ? (float) $payment->amount : (float) $booking->total_amount,
                'rejection_reason' => $latestRejectedLog?->reason,
                'cancellation_reason' => $booking->cancellation?->reason,
            ],
            'pickup' => $this->transformPickupPoint($booking),
            'trip' => $this->transformTrip($booking),
            'cancellation' => $booking->cancellation ? [
                'reason' => $booking->cancellation->reason,
                'canceled_by' => [
                    'id' => $booking->cancellation->canceled_by,
                    'full_name' => $booking->cancellation->canceller?->full_name,
                ],
                'cancellation_time' => $booking->cancellation->cancellation_time?->toIso8601String(),
                'penalty_percentage' => $booking->cancellation->penalty_percentage !== null ? (float) $booking->cancellation->penalty_percentage : null,
                'penalty_amount' => $booking->cancellation->penalty_amount !== null ? (float) $booking->cancellation->penalty_amount : null,
                'wallet_refund_amount' => $booking->cancellation->wallet_refund_amount !== null ? (float) $booking->cancellation->wallet_refund_amount : null,
            ] : null,
            'actions' => [
                'can_cancel' => ! in_array($booking->status?->status_key, ['canceled', 'completed', 'rejected'], true),
                'cancel_endpoint' => "/api/v1/passenger/bookings/{$booking->booking_id}/cancel",
                'tracking_endpoint' => $booking->trip_id !== null ? "/api/v1/passenger/trips/{$booking->trip_id}/tracking" : null,
            ],
        ];
    }

    private function transformTrip(Booking $booking): array
    {
        $trip = $booking->trip;

        return [
            'trip_id' => $trip?->trip_id,
            'status' => [
                'key' => $trip?->status?->status_key,
                'name' => $trip?->status?->status_name,
            ],
            'departure_time' => $trip?->departure_time?->toIso8601String(),
            'from' => [
                'governorate_id' => $trip?->start_governorate_id,
                'name' => $trip?->startGovernorate?->name,
            ],
            'to' => [
                'governorate_id' => $trip?->end_governorate_id,
                'name' => $trip?->endGovernorate?->name,
            ],
            'driver' => [
                'id' => $trip?->driver_id,
                'full_name' => $trip?->driver?->user?->full_name,
                'image' => $trip?->driver?->personal_photo,
                'rating' => $trip?->driver?->user?->rating !== null ? (float) $trip->driver->user->rating : null,
            ],
            'details_endpoint' => $trip?->trip_id !== null ? "/api/v1/passenger/trips/{$trip->trip_id}?trip_type={$booking->booking_type}" : null,
        ];
    }

    private function transformPickupPoint(Booking $booking): ?array
    {
        $pickupPoint = $booking->pickupPoint;

        if (! $pickupPoint) {
            return null;
        }

        return [
            'pickup_point_id' => $pickupPoint->pickup_point_id,
            'trip_point_id' => $pickupPoint->trip_point_id,
            'governorate_id' => $pickupPoint->governorate_id,
            'governorate_name' => $pickupPoint->governorate?->name,
            'point_name' => $pickupPoint->point_name,
            'address' => $pickupPoint->address,
            'display_address' => $this->displayPickupAddress($booking),
            'latitude' => $pickupPoint->latitude !== null ? (float) $pickupPoint->latitude : null,
            'longitude' => $pickupPoint->longitude !== null ? (float) $pickupPoint->longitude : null,
            'meeting_time' => $pickupPoint->meeting_time?->toIso8601String(),
            'is_new' => (bool) $pickupPoint->is_new,
        ];
    }

    private function displayPickupAddress(Booking $booking): ?string
    {
        $pickupPoint = $booking->pickupPoint;

        if (! $pickupPoint) {
            return null;
        }

        $name = trim((string) $pickupPoint->point_name);

        if ($name !== '') {
            return $name;
        }

        if ($pickupPoint->tripPoint) {
            return $this->displayAddress($pickupPoint->tripPoint);
        }

        return $this->cleanAddress($pickupPoint->address);
    }

    private function displayAddress(?TripPoint $point): ?string
    {
        if (! $point) {
            return null;
        }

        $note = trim((string) $point->note);

        if ($note !== '') {
            return $note;
        }

        return $this->cleanAddress($point->address);
    }

    private function cleanAddress(?string $address): ?string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return null;
        }

        $parts = collect(preg_split('/،|,/', $address) ?: [])
            ->map(fn (string $part) => trim($part))
            ->filter(function (string $part) {
                if ($part === '' || $part === 'سوريا') {
                    return false;
                }

                return ! preg_match('/^[A-Z0-9]{3,}\+[A-Z0-9]{2,}$/i', $part);
            })
            ->values();

        return $parts->isNotEmpty() ? $parts->implode('، ') : $address;
    }
}
