<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripPoint;

class PassengerTripCardService
{
    public function transform(Trip $trip, ?string $tripType = null, array $distance = []): array
    {
        $vehicle = $trip->driver?->vehicles?->first();
        $orderedPoints = $trip->points->sortBy('sequence_order')->values();
        $startPoint = $orderedPoints->firstWhere('point_type', 'start') ?? $orderedPoints->first();
        $endPoint = $orderedPoints->firstWhere('point_type', 'end') ?? $orderedPoints->last();

        return [
            'trip_id' => $trip->trip_id,
            'cluster_id' => $trip->cluster_id,
            'is_booking_visible' => (bool) $trip->is_booking_visible,
            'type' => [
                'requested' => $tripType,
                'allow_shared' => (bool) $trip->allow_shared,
                'allow_private' => (bool) $trip->allow_private,
            ],
            'departure_time' => \App\Support\ApiDateTime::toAppIso($trip->departure_time),
            'from' => [
                'governorate_id' => $trip->start_governorate_id,
                'name' => $trip->startGovernorate?->name,
                'address' => $startPoint?->address,
                'display_address' => $this->displayAddress($startPoint),
            ],
            'to' => [
                'governorate_id' => $trip->end_governorate_id,
                'name' => $trip->endGovernorate?->name,
                'address' => $endPoint?->address,
                'display_address' => $this->displayAddress($endPoint),
            ],
            'driver' => [
                'id' => $trip->driver_id,
                'full_name' => $trip->driver?->user?->full_name,
                'image' => $trip->driver?->personal_photo,
                'rating' => $trip->driver?->user?->rating !== null ? (float) $trip->driver->user->rating : null,
            ],
            'vehicle' => [
                'type' => $vehicle?->car_type,
                'vehicle_category' => $vehicle?->categoryPayload(),
                'image' => $vehicle?->images?->first()?->image_url,
            ],
            'pricing' => [
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'display_price' => $this->priceForType($trip, $tripType),
            ],
            'available_seats' => (int) $trip->available_seats,
            'distance' => [
                'pickup_km' => array_key_exists('pickup_km', $distance) && $distance['pickup_km'] !== null
                    ? round((float) $distance['pickup_km'], 3)
                    : null,
                'dropoff_km' => array_key_exists('dropoff_km', $distance) && $distance['dropoff_km'] !== null
                    ? round((float) $distance['dropoff_km'], 3)
                    : null,
                'score_km' => array_key_exists('score_km', $distance) && $distance['score_km'] !== null
                    ? round((float) $distance['score_km'], 3)
                    : null,
            ],
            'details_endpoint' => "/api/v1/passenger/trips/{$trip->trip_id}",
            'booking_endpoint' => '/api/v1/passenger/bookings',
        ];
    }

    public function priceForType(Trip $trip, ?string $tripType): float
    {
        $price = match ($tripType) {
            'private' => $trip->private_price,
            'shared' => $trip->shared_price,
            default => $trip->allow_shared && $trip->shared_price !== null ? $trip->shared_price : $trip->private_price,
        };

        return $price !== null ? (float) $price : PHP_INT_MAX;
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
