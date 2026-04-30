<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TripPoint;
use App\Models\TripStatus;
use App\Models\User;
use App\Repositories\PassengerTripRepository;

class PassengerTripHistoryService
{
    public function __construct(private readonly PassengerTripRepository $passengerTripRepository)
    {
    }

    public function listTrips(User $passenger): array
    {
        return $this->listByTripStatuses($passenger, [
            TripStatus::PENDING,
            TripStatus::ACTIVE,
            TripStatus::COMPLETED,
            TripStatus::AUTO_COMPLETED,
            TripStatus::CANCELED,
        ]);
    }

    public function current(User $passenger): array
    {
        return $this->listByTripStatuses($passenger, [TripStatus::ACTIVE]);
    }

    public function pending(User $passenger): array
    {
        return $this->listByTripStatuses($passenger, [TripStatus::PENDING]);
    }

    public function completed(User $passenger): array
    {
        return $this->listByTripStatuses($passenger, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED]);
    }

    public function canceled(User $passenger): array
    {
        return $this->listByTripStatuses($passenger, [TripStatus::CANCELED]);
    }

    private function listByTripStatuses(User $passenger, array $tripStatusKeys): array
    {
        $items = Booking::query()
            ->with([
                'status',
                'pickupPoint.governorate',
                'pickupPoint.tripPoint',
                'trip.status',
                'trip.points',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'trip.driver.user',
                'trip.driver.vehicles.images',
                'review',
            ])
            ->where('passenger_id', $passenger->user_id)
            ->whereHas('trip.status', fn ($query) => $query->whereIn('status_key', $tripStatusKeys))
            ->whereHas('status', fn ($query) => $query->where('status_key', '!=', 'rejected'))
            ->get()
            ->sortBy(fn (Booking $booking) => abs(now()->diffInSeconds($booking->trip?->departure_time ?? now(), false)))
            ->map(fn (Booking $booking) => $this->transformBookingTripCard($booking))
            ->values();

        return [
            'items' => $items,
            'total' => $items->count(),
        ];
    }

    private function transformBookingTripCard(Booking $booking): array
    {
        $trip = $booking->trip;
        $vehicle = $trip?->driver?->vehicles?->first();
        $orderedPoints = $trip?->points?->sortBy('sequence_order')->values() ?? collect();
        $startPoint = $orderedPoints->firstWhere('point_type', 'start') ?? $orderedPoints->first();
        $endPoint = $orderedPoints->firstWhere('point_type', 'end') ?? $orderedPoints->last();

        return [
            'booking' => [
                'booking_id' => $booking->booking_id,
                'booking_code' => $booking->booking_code,
                'status' => [
                    'key' => $booking->status?->status_key,
                    'name' => $booking->status?->status_name,
                ],
                'booking_type' => $booking->booking_type,
                'seats_reserved' => (int) $booking->seats_reserved,
                'total_amount' => $booking->total_amount !== null ? (float) $booking->total_amount : null,
                'created_at' => $booking->created_at?->toIso8601String(),
            ],
            'trip_id' => $trip?->trip_id,
            'cluster_id' => $trip?->cluster_id,
            'is_booking_visible' => (bool) ($trip?->is_booking_visible ?? false),
            'status' => [
                'key' => $trip?->status?->status_key,
                'name' => $trip?->status?->status_name,
            ],
            'type' => [
                'requested' => $booking->booking_type,
                'allow_shared' => (bool) ($trip?->allow_shared ?? false),
                'allow_private' => (bool) ($trip?->allow_private ?? false),
            ],
            'departure_time' => $trip?->departure_time?->toIso8601String(),
            'from' => [
                'governorate_id' => $trip?->start_governorate_id,
                'name' => $trip?->startGovernorate?->name,
                'address' => $startPoint?->address,
                'display_address' => $this->displayAddress($startPoint),
            ],
            'to' => [
                'governorate_id' => $trip?->end_governorate_id,
                'name' => $trip?->endGovernorate?->name,
                'address' => $endPoint?->address,
                'display_address' => $this->displayAddress($endPoint),
            ],
            'pickup' => [
                'pickup_point_id' => $booking->pickupPoint?->pickup_point_id,
                'trip_point_id' => $booking->pickupPoint?->trip_point_id,
                'governorate_id' => $booking->pickupPoint?->governorate_id,
                'governorate_name' => $booking->pickupPoint?->governorate?->name,
                'point_name' => $booking->pickupPoint?->point_name,
                'address' => $booking->pickupPoint?->address,
                'display_address' => $this->displayPickupAddress($booking),
                'latitude' => $booking->pickupPoint?->latitude !== null ? (float) $booking->pickupPoint->latitude : null,
                'longitude' => $booking->pickupPoint?->longitude !== null ? (float) $booking->pickupPoint->longitude : null,
                'meeting_time' => $booking->pickupPoint?->meeting_time?->toIso8601String(),
                'is_new' => (bool) ($booking->pickupPoint?->is_new ?? false),
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
            'pricing' => [
                'shared_price' => $trip?->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip?->private_price !== null ? (float) $trip->private_price : null,
                'display_price' => $this->priceForBookingType($booking),
            ],
            'available_seats' => $trip?->available_seats !== null ? (int) $trip->available_seats : null,
            'is_rated' => $booking->review !== null,
            'details_endpoint' => $trip?->trip_id !== null ? "/api/v1/passenger/trips/{$trip->trip_id}?trip_type={$booking->booking_type}" : null,
            'tracking_endpoint' => $trip?->trip_id !== null ? "/api/v1/passenger/trips/{$trip->trip_id}/tracking" : null,
            'rating_endpoint' => $trip?->trip_id !== null ? "/api/v1/passenger/rate-trip/{$trip->trip_id}" : null,
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

    private function priceForBookingType(Booking $booking): ?float
    {
        $price = $booking->booking_type === 'private'
            ? $booking->trip?->private_price
            : $booking->trip?->shared_price;

        return $price !== null ? (float) $price : null;
    }
}
