<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use RuntimeException;

class PassengerTripDetailsService
{
    public function show(int $tripId, ?string $requestedType = null): array
    {
        if ($requestedType !== null && ! in_array($requestedType, ['shared', 'private'], true)) {
            throw new RuntimeException('نوع الرحلة المطلوب غير صالح.', 422);
        }

        $trip = Trip::query()
            ->with([
                'status',
                'points',
                'driver.user',
                'driver.vehicles.images',
                'startGovernorate',
                'endGovernorate',
                'bookings.status',
                'bookings.passenger',
            ])
            ->find($tripId);

        if (! $trip) {
            throw new RuntimeException('الرحلة غير موجودة.', 404);
        }

        if (! in_array($trip->status?->status_key, [TripStatus::PENDING, TripStatus::ACTIVE], true)) {
            throw new RuntimeException('هذه الرحلة غير متاحة للحجز حالياً.', 422);
        }

        $this->ensureRequestedTypeIsAvailable($trip, $requestedType);

        return $this->transform($trip, $requestedType);
    }

    private function ensureRequestedTypeIsAvailable(Trip $trip, ?string $requestedType): void
    {
        if ($requestedType === 'shared' && ! (bool) $trip->allow_shared) {
            throw new RuntimeException('هذه الرحلة لا تدعم الحجز المشترك.', 422);
        }

        if ($requestedType === 'private' && ! (bool) $trip->allow_private) {
            throw new RuntimeException('هذه الرحلة لا تدعم الحجز الخاص.', 422);
        }
    }

    private function transform(Trip $trip, ?string $requestedType): array
    {
        $points = $trip->points->sortBy('sequence_order')->values();
        $startPoint = $points->firstWhere('point_type', 'start') ?? $points->first();
        $endPoint = $points->firstWhere('point_type', 'end') ?? $points->last();
        $vehicle = $trip->driver?->vehicles?->first();
        $activeBookings = $trip->bookings
            ->filter(fn (Booking $booking) => ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
            ->values();

        return [
            'trip_id' => $trip->trip_id,
            'status' => [
                'key' => $trip->status?->status_key,
                'name' => $trip->status?->status_name,
            ],
            'type' => [
                'requested' => $requestedType,
                'allow_shared' => (bool) $trip->allow_shared,
                'allow_private' => (bool) $trip->allow_private,
                'is_private_booked' => (bool) $trip->is_private_booked,
            ],
            'vehicle' => [
                'type' => $vehicle?->car_type,
                'model' => $vehicle?->certified_agency,
                'seat_capacity' => $vehicle?->seat_capacity !== null ? (int) $vehicle->seat_capacity : null,
                'plate_number' => $vehicle?->ownership_document,
                'amenities' => [],
                'image' => $vehicle?->images?->first()?->image_url,
                'images' => $vehicle?->images?->pluck('image_url')->values() ?? [],
            ],
            'driver' => [
                'id' => $trip->driver_id,
                'full_name' => $trip->driver?->user?->full_name,
                'image' => $trip->driver?->personal_photo,
                'rating' => $trip->driver?->user?->rating !== null ? (float) $trip->driver->user->rating : null,
                'profile_endpoint' => "/api/v1/passenger/drivers/{$trip->driver_id}",
            ],
            'schedule' => [
                'departure_time' => $trip->departure_time?->toIso8601String(),
                'expected_arrival_time' => $this->expectedArrival($trip)?->toIso8601String(),
            ],
            'route' => [
                'from' => [
                    'governorate_id' => $trip->start_governorate_id,
                    'name' => $trip->startGovernorate?->name,
                    'address' => $startPoint?->address,
                    'display_address' => $this->displayAddress($startPoint),
                    'latitude' => $startPoint?->latitude !== null ? (float) $startPoint->latitude : null,
                    'longitude' => $startPoint?->longitude !== null ? (float) $startPoint->longitude : null,
                ],
                'to' => [
                    'governorate_id' => $trip->end_governorate_id,
                    'name' => $trip->endGovernorate?->name,
                    'address' => $endPoint?->address,
                    'display_address' => $this->displayAddress($endPoint),
                    'latitude' => $endPoint?->latitude !== null ? (float) $endPoint->latitude : null,
                    'longitude' => $endPoint?->longitude !== null ? (float) $endPoint->longitude : null,
                ],
                'polyline' => $trip->route_polyline,
                'estimated_distance_km' => $trip->estimated_distance_km !== null ? (float) $trip->estimated_distance_km : null,
                'estimated_duration_minutes' => $trip->estimated_duration_minutes !== null ? (int) $trip->estimated_duration_minutes : null,
                'points' => $points->map(fn (TripPoint $point) => [
                    'point_id' => $point->point_id,
                    'type' => $point->point_type,
                    'address' => $point->address,
                    'display_address' => $this->displayAddress($point),
                    'note' => $point->note,
                    'latitude' => (float) $point->latitude,
                    'longitude' => (float) $point->longitude,
                    'sequence_order' => (int) $point->sequence_order,
                    'expected_arrival_time' => $point->expected_arrival_time?->toIso8601String(),
                ])->values(),
            ],
            'pricing' => [
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'display_price' => $this->displayPrice($trip, $requestedType),
            ],
            'available_seats' => $requestedType !== 'private' ? (int) $trip->available_seats : null,
            'passengers' => $requestedType !== 'private' && $trip->allow_shared
                ? $activeBookings->map(fn (Booking $booking) => [
                    'booking_id' => $booking->booking_id,
                    'passenger_id' => $booking->passenger_id,
                    'full_name' => $booking->passenger?->full_name,
                    'rating' => $booking->passenger?->rating !== null ? (float) $booking->passenger->rating : null,
                    'seats_reserved' => (int) $booking->seats_reserved,
                    'profile_endpoint' => "/api/v1/passenger/users/{$booking->passenger_id}",
                ])->values()
                : [],
            'actions' => [
                'can_book_shared' => (bool) $trip->allow_shared && (bool) $trip->is_booking_visible && ! (bool) $trip->is_private_booked && (int) $trip->available_seats > 0,
                'can_book_private' => (bool) $trip->allow_private && ! (bool) $trip->is_private_booked,
                'booking_endpoint' => '/api/v1/passenger/bookings',
                'tracking_endpoint' => "/api/v1/passenger/trips/{$trip->trip_id}/tracking",
            ],
        ];
    }

    private function displayPrice(Trip $trip, ?string $requestedType): ?float
    {
        return match ($requestedType) {
            'shared' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
            'private' => $trip->private_price !== null ? (float) $trip->private_price : null,
            default => null,
        };
    }

    private function expectedArrival(Trip $trip): ?\Carbon\CarbonInterface
    {
        $lastPointArrival = $trip->points
            ->sortByDesc('sequence_order')
            ->first(fn (TripPoint $point) => $point->expected_arrival_time !== null)
            ?->expected_arrival_time;

        if ($lastPointArrival) {
            return $lastPointArrival;
        }

        if (! $trip->departure_time || ! $trip->estimated_duration_minutes) {
            return null;
        }

        return $trip->departure_time->copy()->addMinutes((int) $trip->estimated_duration_minutes);
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

        $address = trim((string) $point->address);

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
