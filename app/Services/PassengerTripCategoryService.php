<?php

namespace App\Services;

use App\Models\Governorate;
use App\Models\Trip;
use App\Models\TripPoint;
use App\Models\TripStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class PassengerTripCategoryService
{
    public function categories(array $filters): array
    {
        $departureFrom = $this->departureFrom($filters);
        $tripType = $filters['trip_type'] ?? 'shared';
        $startGovernorateId = $filters['start_governorate_id'] ?? null;

        $items = Governorate::query()
            ->where('is_active', true)
            ->withCount([
                'tripsTo as available_trips_count' => function ($query) use ($departureFrom, $tripType, $startGovernorateId) {
                    $this->applyAvailableTripFilters($query, $departureFrom, $tripType, $startGovernorateId);
                },
            ])
            ->orderBy('governorate_id')
            ->get()
            ->map(fn (Governorate $governorate) => [
                'governorate_id' => $governorate->governorate_id,
                'name' => $governorate->name,
                'image' => $governorate->image_url,
                'available_trips_count' => (int) $governorate->available_trips_count,
                'trips_endpoint' => "/api/v1/passenger/trip-categories/{$governorate->governorate_id}/trips",
            ])
            ->values();

        return [
            'items' => $items,
            'meta' => [
                'total' => $items->count(),
                'start_governorate_id' => $startGovernorateId !== null ? (int) $startGovernorateId : null,
                'start_filter_label' => $startGovernorateId !== null ? 'محافظة محددة' : 'كل المحافظات',
                'trip_type' => $tripType,
                'departure_date' => $departureFrom->toDateString(),
            ],
        ];
    }

    public function trips(int $destinationGovernorateId, array $filters): array
    {
        $destination = Governorate::query()
            ->where('is_active', true)
            ->find($destinationGovernorateId);

        if (! $destination) {
            throw new RuntimeException('تصنيف المحافظة غير موجود.', 404);
        }

        $departureFrom = $this->departureFrom($filters);
        $tripType = $filters['trip_type'] ?? 'shared';
        $startGovernorateId = $filters['start_governorate_id'] ?? null;
        $perPage = (int) ($filters['per_page'] ?? 20);
        $hasPickupPoint = isset($filters['pickup_latitude'], $filters['pickup_longitude']);
        $hasDropoffPoint = isset($filters['dropoff_latitude'], $filters['dropoff_longitude']);
        $hasPointSearch = $hasPickupPoint || $hasDropoffPoint;

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
            ->where('end_governorate_id', $destination->governorate_id);

        $this->applyAvailableTripFilters($query, $departureFrom, $tripType, $startGovernorateId);

        $trips = $query->get()
            ->map(fn (Trip $trip) => $this->attachSearchMetrics($trip, $filters, $hasPickupPoint, $hasDropoffPoint))
            ->filter(fn (array $item) => $item['matches_points'])
            ->sort(fn (array $left, array $right) => $this->compareTrips($left, $right, $tripType, $hasPointSearch))
            ->values();

        return [
            'category' => [
                'governorate_id' => $destination->governorate_id,
                'name' => $destination->name,
                'image' => $destination->image_url,
            ],
            'items' => $trips
                ->take($perPage)
                ->map(fn (array $item) => $this->transformTripCard($item, $tripType))
                ->values(),
            'meta' => [
                'total' => $trips->count(),
                'returned' => min($trips->count(), $perPage),
                'per_page' => $perPage,
                'start_governorate_id' => $startGovernorateId !== null ? (int) $startGovernorateId : null,
                'start_filter_label' => $startGovernorateId !== null ? 'محافظة محددة' : 'كل المحافظات',
                'trip_type' => $tripType,
                'departure_date' => $departureFrom->toDateString(),
                'search_mode' => $hasPointSearch ? 'points' : 'governorates',
            ],
        ];
    }

    private function applyAvailableTripFilters($query, Carbon $departureFrom, ?string $tripType, mixed $startGovernorateId): void
    {
        $query->where('departure_time', '>=', $departureFrom)
            ->whereHas('status', function ($builder) {
                $builder->whereIn('status_key', [TripStatus::PENDING, TripStatus::ACTIVE]);
            });

        if ($startGovernorateId !== null) {
            $query->where('start_governorate_id', (int) $startGovernorateId);
        }

        if ($tripType === 'shared') {
            $query->where('allow_shared', true)
                ->where('is_private_booked', false)
                ->where('is_booking_visible', true)
                ->where('available_seats', '>', 0);

            return;
        }

        if ($tripType === 'private') {
            $query->where('allow_private', true)
                ->where('is_private_booked', false);

            return;
        }

        $query->where(function ($builder) {
            $builder->where(function ($shared) {
                $shared->where('allow_shared', true)
                    ->where('is_private_booked', false)
                    ->where('is_booking_visible', true)
                    ->where('available_seats', '>', 0);
            })->orWhere(function ($private) {
                $private->where('allow_private', true)
                    ->where('is_private_booked', false);
            });
        });
    }

    private function attachSearchMetrics(Trip $trip, array $filters, bool $hasPickupPoint, bool $hasDropoffPoint): array
    {
        $pickupMatch = null;
        $dropoffMatch = null;
        $matchesPoints = true;

        if ($hasPickupPoint) {
            $pickupMatch = $this->closestPointOnTripPath(
                (float) $filters['pickup_latitude'],
                (float) $filters['pickup_longitude'],
                $trip->points
            );
        }

        if ($hasDropoffPoint) {
            $dropoffMatch = $this->closestPointOnTripPath(
                (float) $filters['dropoff_latitude'],
                (float) $filters['dropoff_longitude'],
                $trip->points
            );
        }

        if ($pickupMatch && $dropoffMatch) {
            $matchesPoints = $pickupMatch['progress'] <= $dropoffMatch['progress'];
        }

        return [
            'trip' => $trip,
            'pickup_distance_km' => $pickupMatch['distance_km'] ?? null,
            'dropoff_distance_km' => $dropoffMatch['distance_km'] ?? null,
            'distance_score_km' => $this->distanceScore($pickupMatch, $dropoffMatch),
            'matches_points' => $matchesPoints,
        ];
    }

    private function compareTrips(array $left, array $right, ?string $tripType, bool $hasPointSearch): int
    {
        /** @var Trip $leftTrip */
        $leftTrip = $left['trip'];
        /** @var Trip $rightTrip */
        $rightTrip = $right['trip'];

        $leftSort = [
            optional($leftTrip->departure_time)->timestamp ?? PHP_INT_MAX,
            -1 * (float) ($leftTrip->driver?->user?->rating ?? 0),
            (int) $leftTrip->available_seats,
            $this->priceForType($leftTrip, $tripType),
            (int) $leftTrip->trip_id,
        ];

        $rightSort = [
            optional($rightTrip->departure_time)->timestamp ?? PHP_INT_MAX,
            -1 * (float) ($rightTrip->driver?->user?->rating ?? 0),
            (int) $rightTrip->available_seats,
            $this->priceForType($rightTrip, $tripType),
            (int) $rightTrip->trip_id,
        ];

        if ($hasPointSearch) {
            array_unshift($leftSort, $left['distance_score_km'] ?? PHP_INT_MAX);
            array_unshift($rightSort, $right['distance_score_km'] ?? PHP_INT_MAX);
        }

        return $leftSort <=> $rightSort;
    }

    private function transformTripCard(array $item, ?string $tripType): array
    {
        /** @var Trip $trip */
        $trip = $item['trip'];
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
            'departure_time' => $trip->departure_time?->toIso8601String(),
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
                'image' => $vehicle?->images?->first()?->image_url,
            ],
            'pricing' => [
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'display_price' => $this->priceForType($trip, $tripType),
            ],
            'available_seats' => (int) $trip->available_seats,
            'distance' => [
                'pickup_km' => $item['pickup_distance_km'] !== null ? round((float) $item['pickup_distance_km'], 3) : null,
                'dropoff_km' => $item['dropoff_distance_km'] !== null ? round((float) $item['dropoff_distance_km'], 3) : null,
                'score_km' => $item['distance_score_km'] !== null ? round((float) $item['distance_score_km'], 3) : null,
            ],
            'details_endpoint' => "/api/v1/passenger/trips/{$trip->trip_id}",
            'booking_endpoint' => '/api/v1/passenger/bookings',
        ];
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

    private function priceForType(Trip $trip, ?string $tripType): float
    {
        $price = match ($tripType) {
            'private' => $trip->private_price,
            'shared' => $trip->shared_price,
            default => $trip->allow_shared && $trip->shared_price !== null ? $trip->shared_price : $trip->private_price,
        };

        return $price !== null ? (float) $price : PHP_INT_MAX;
    }

    private function distanceScore(?array $pickupMatch, ?array $dropoffMatch): ?float
    {
        $score = null;

        foreach ([$pickupMatch, $dropoffMatch] as $match) {
            if (! $match) {
                continue;
            }

            $score = ($score ?? 0.0) + (float) $match['distance_km'];
        }

        return $score;
    }

    private function closestPointOnTripPath(float $latitude, float $longitude, Collection $points): array
    {
        $orderedPoints = $points
            ->sortBy('sequence_order')
            ->values();

        if ($orderedPoints->count() === 0) {
            return [
                'distance_km' => PHP_INT_MAX,
                'progress' => PHP_INT_MAX,
            ];
        }

        if ($orderedPoints->count() === 1) {
            $point = $orderedPoints->first();

            return [
                'distance_km' => $this->distanceKm($latitude, $longitude, (float) $point->latitude, (float) $point->longitude),
                'progress' => 0.0,
            ];
        }

        $closest = [
            'distance_km' => PHP_INT_MAX,
            'progress' => PHP_INT_MAX,
        ];

        for ($index = 0; $index < $orderedPoints->count() - 1; $index++) {
            $start = $orderedPoints[$index];
            $end = $orderedPoints[$index + 1];
            $segmentMatch = $this->distanceToSegmentKm($latitude, $longitude, $start, $end);

            if ($segmentMatch['distance_km'] < $closest['distance_km']) {
                $closest = [
                    'distance_km' => $segmentMatch['distance_km'],
                    'progress' => $index + $segmentMatch['projection'],
                ];
            }
        }

        return $closest;
    }

    private function distanceToSegmentKm(float $latitude, float $longitude, TripPoint $start, TripPoint $end): array
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
            return [
                'distance_km' => $this->distanceKm($latitude, $longitude, $y1, $x1),
                'projection' => 0.0,
            ];
        }

        $projection = (($x - $x1) * $dx + ($y - $y1) * $dy) / (($dx * $dx) + ($dy * $dy));
        $projection = max(0.0, min(1.0, $projection));
        $closestLon = $x1 + ($projection * $dx);
        $closestLat = $y1 + ($projection * $dy);

        return [
            'distance_km' => $this->distanceKm($latitude, $longitude, $closestLat, $closestLon),
            'projection' => $projection,
        ];
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

    private function departureFrom(array $filters): Carbon
    {
        if (! isset($filters['departure_date'])) {
            return now();
        }

        $rawDate = (string) $filters['departure_date'];
        $requested = Carbon::parse($rawDate);
        $hasExplicitTime = preg_match('/\d{2}:\d{2}/', $rawDate) === 1;
        $departureFrom = $hasExplicitTime ? $requested : $requested->startOfDay();

        return $departureFrom->isPast() ? now() : $departureFrom;
    }
}
