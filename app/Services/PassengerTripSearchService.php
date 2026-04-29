<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PassengerTripSearchService
{
    private const LOCAL_MATCH_DISTANCE_KM = 2.0;
    private const INTERCITY_MATCH_DISTANCE_KM = 5.0;

    public function search(array $filters): array
    {
        $tripType = (string) $filters['trip_type'];
        $seatsRequired = max(1, (int) ($filters['seats_required'] ?? 1));
        $departureFrom = Carbon::parse($filters['departure_date'])->startOfDay();
        $hasUserPoint = isset($filters['latitude'], $filters['longitude']);

        $query = Trip::query()
            ->with([
                'status',
                'points',
                'driver.user',
                'driver.vehicles.images',
                'startGovernorate',
                'endGovernorate',
                'cluster',
            ])
            ->where('start_governorate_id', (int) $filters['start_governorate_id'])
            ->where('end_governorate_id', (int) $filters['end_governorate_id'])
            ->where('departure_time', '>=', $departureFrom)
            ->whereHas('status', function ($builder) {
                $builder->whereIn('status_key', [TripStatus::PENDING, TripStatus::ACTIVE]);
            });

        if ($tripType === 'shared') {
            $query->where('allow_shared', true)
                ->where('is_private_booked', false)
                ->where('is_booking_visible', true)
                ->where('available_seats', '>=', $seatsRequired);
        } else {
            $query->where('allow_private', true)
                ->where('is_private_booked', false);
        }

        $trips = $query->get()
            ->map(fn (Trip $trip) => $this->attachSearchMetrics($trip, $filters, $hasUserPoint))
            ->filter(fn (array $item) => $item['matches_user_point'])
            ->sort(fn (array $left, array $right) => $this->compareResults($left, $right, $tripType))
            ->values();

        $perPage = (int) ($filters['per_page'] ?? 20);

        return [
            'items' => $trips
                ->take($perPage)
                ->map(fn (array $item) => $this->transformTripCard($item, $tripType))
                ->values(),
            'meta' => [
                'total' => $trips->count(),
                'returned' => min($trips->count(), $perPage),
                'per_page' => $perPage,
                'trip_type' => $tripType,
            ],
        ];
    }

    private function attachSearchMetrics(Trip $trip, array $filters, bool $hasUserPoint): array
    {
        $distanceKm = null;
        $matchesUserPoint = true;

        if ($hasUserPoint) {
            $distanceKm = $this->distanceToTripPathKm(
                (float) $filters['latitude'],
                (float) $filters['longitude'],
                $trip->points
            );

            $matchesUserPoint = $distanceKm <= $this->matchDistanceKm($trip);
        }

        return [
            'trip' => $trip,
            'distance_km' => $distanceKm,
            'matches_user_point' => $matchesUserPoint,
        ];
    }

    private function compareResults(array $left, array $right, string $tripType): int
    {
        /** @var Trip $leftTrip */
        $leftTrip = $left['trip'];
        /** @var Trip $rightTrip */
        $rightTrip = $right['trip'];

        return [
            $left['distance_km'] ?? 0,
            optional($leftTrip->departure_time)->timestamp ?? PHP_INT_MAX,
            -1 * (float) ($leftTrip->driver?->user?->rating ?? 0),
            -1 * (int) $leftTrip->available_seats,
            $this->priceForType($leftTrip, $tripType),
            (int) $leftTrip->trip_id,
        ] <=> [
            $right['distance_km'] ?? 0,
            optional($rightTrip->departure_time)->timestamp ?? PHP_INT_MAX,
            -1 * (float) ($rightTrip->driver?->user?->rating ?? 0),
            -1 * (int) $rightTrip->available_seats,
            $this->priceForType($rightTrip, $tripType),
            (int) $rightTrip->trip_id,
        ];
    }

    private function transformTripCard(array $item, string $tripType): array
    {
        /** @var Trip $trip */
        $trip = $item['trip'];
        $vehicle = $trip->driver?->vehicles?->first();

        return [
            'trip_id' => $trip->trip_id,
            'cluster_id' => $trip->cluster_id,
            'is_booking_visible' => (bool) $trip->is_booking_visible,
            'type' => [
                'requested' => $tripType,
                'allow_shared' => (bool) $trip->allow_shared,
                'allow_private' => (bool) $trip->allow_private,
            ],
            'departure_time' => optional($trip->departure_time)->toIso8601String(),
            'from' => [
                'governorate_id' => $trip->start_governorate_id,
                'name' => $trip->startGovernorate?->name,
            ],
            'to' => [
                'governorate_id' => $trip->end_governorate_id,
                'name' => $trip->endGovernorate?->name,
            ],
            'driver' => [
                'id' => $trip->driver_id,
                'full_name' => $trip->driver?->user?->full_name,
                'rating' => $trip->driver?->user?->rating !== null ? (float) $trip->driver->user->rating : null,
            ],
            'vehicle' => [
                'type' => $vehicle?->car_type,
                'image' => $vehicle?->images?->first()?->image_url,
            ],
            'pricing' => [
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'display_price' => $this->priceForType($trip, $tripType),
            ],
            'available_seats' => (int) $trip->available_seats,
            'distance_to_user_km' => $item['distance_km'] !== null ? round((float) $item['distance_km'], 3) : null,
            'details_endpoint' => "/api/v1/passenger/trips/{$trip->trip_id}",
            'booking_endpoint' => '/api/v1/passenger/bookings',
        ];
    }

    private function priceForType(Trip $trip, string $tripType): float
    {
        $price = $tripType === 'shared' ? $trip->shared_price : $trip->private_price;

        return $price !== null ? (float) $price : PHP_INT_MAX;
    }

    private function matchDistanceKm(Trip $trip): float
    {
        return (int) $trip->start_governorate_id === (int) $trip->end_governorate_id
            ? self::LOCAL_MATCH_DISTANCE_KM
            : self::INTERCITY_MATCH_DISTANCE_KM;
    }

    private function distanceToTripPathKm(float $latitude, float $longitude, Collection $points): float
    {
        $orderedPoints = $points
            ->sortBy('sequence_order')
            ->values();

        if ($orderedPoints->count() === 0) {
            return PHP_INT_MAX;
        }

        if ($orderedPoints->count() === 1) {
            $point = $orderedPoints->first();

            return $this->distanceKm($latitude, $longitude, (float) $point->latitude, (float) $point->longitude);
        }

        $minDistance = PHP_INT_MAX;

        for ($index = 0; $index < $orderedPoints->count() - 1; $index++) {
            $start = $orderedPoints[$index];
            $end = $orderedPoints[$index + 1];
            $minDistance = min(
                $minDistance,
                $this->distanceToSegmentKm($latitude, $longitude, $start, $end)
            );
        }

        return $minDistance;
    }

    private function distanceToSegmentKm(float $latitude, float $longitude, TripPoint $start, TripPoint $end): float
    {
        $x = $longitude;
        $y = $latitude;
        $x1 = (float) $start->longitude;
        $y1 = (float) $start->latitude;
        $x2 = (float) $end->longitude;
        $y2 = (float) $end->latitude;
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;

        if ($dx == 0.0 && $dy == 0.0) {
            return $this->distanceKm($latitude, $longitude, $y1, $x1);
        }

        $projection = (($x - $x1) * $dx + ($y - $y1) * $dy) / (($dx * $dx) + ($dy * $dy));
        $projection = max(0.0, min(1.0, $projection));
        $closestLon = $x1 + ($projection * $dx);
        $closestLat = $y1 + ($projection * $dy);

        return $this->distanceKm($latitude, $longitude, $closestLat, $closestLon);
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1, sqrt($a)));
    }
}
