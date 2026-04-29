<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripCluster;
use App\Models\TripPoint;
use App\Models\TripStatus;
use Carbon\Carbon;

class TripClusterService
{
    private const OPEN_TRIPS_LIMIT = 3;
    private const LOCAL_PROXIMITY_KM = 2.0;
    private const INTERCITY_PROXIMITY_KM = 5.0;
    private const LOCAL_TIME_WINDOW_MINUTES = 15;
    private const INTERCITY_TIME_WINDOW_MINUTES = 60;

    public function assignTripToCluster(Trip $trip): void
    {
        $trip->loadMissing(['points', 'status']);

        if (! $this->shouldBeClustered($trip)) {
            $trip->forceFill([
                'cluster_id' => null,
                'cluster_assigned_at' => null,
                'is_booking_visible' => true,
            ])->save();

            return;
        }

        $startPoint = $this->resolvePoint($trip, 'start');
        $endPoint = $this->resolvePoint($trip, 'end');

        if (! $startPoint || ! $endPoint) {
            $trip->forceFill(['is_booking_visible' => true])->save();

            return;
        }

        $cluster = $this->findBestCluster($trip, $startPoint, $endPoint)
            ?? $this->createClusterForTrip($trip, $startPoint, $endPoint);

        $trip->forceFill([
            'cluster_id' => $cluster->cluster_id,
            'cluster_assigned_at' => now(),
        ])->save();

        $this->refreshClusterAvailability((int) $cluster->cluster_id);
    }

    public function refreshClusterAvailability(?int $clusterId): void
    {
        if (! $clusterId) {
            return;
        }

        $cluster = TripCluster::query()->find($clusterId);

        if (! $cluster) {
            return;
        }

        $trips = Trip::query()
            ->where('cluster_id', $cluster->cluster_id)
            ->with(['status', 'bookings.status', 'driver.user'])
            ->get();

        $eligibleTrips = $trips
            ->filter(fn (Trip $trip) => $this->isEligibleForSharedVisibility($trip))
            ->values();

        $pinnedOpenTrips = $eligibleTrips
            ->filter(fn (Trip $trip) => (bool) $trip->is_booking_visible && $this->activeSharedSeats($trip) > 0)
            ->values();

        $openTripIds = $pinnedOpenTrips
            ->pluck('trip_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $remainingSlots = max(0, (int) $cluster->open_trips_limit - count($openTripIds));

        if ($remainingSlots > 0) {
            $rankedCandidates = $eligibleTrips
                ->reject(fn (Trip $trip) => in_array((int) $trip->trip_id, $openTripIds, true))
                ->sort(fn (Trip $left, Trip $right) => $this->compareAvailability($left, $right))
                ->take($remainingSlots)
                ->pluck('trip_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $openTripIds = array_values(array_unique([...$openTripIds, ...$rankedCandidates]));
        }

        foreach ($trips as $trip) {
            $trip->forceFill([
                'is_booking_visible' => in_array((int) $trip->trip_id, $openTripIds, true),
            ])->save();
        }
    }

    private function shouldBeClustered(Trip $trip): bool
    {
        return (bool) $trip->allow_shared
            && ! (bool) $trip->is_private_booked
            && $trip->shared_price !== null;
    }

    private function isEligibleForSharedVisibility(Trip $trip): bool
    {
        $statusKey = $trip->status?->status_key;

        return $this->shouldBeClustered($trip)
            && in_array($statusKey, [TripStatus::PENDING, TripStatus::ACTIVE], true)
            && (int) $trip->available_seats > 0;
    }

    private function findBestCluster(Trip $trip, TripPoint $startPoint, TripPoint $endPoint): ?TripCluster
    {
        $departure = Carbon::parse($trip->departure_time);
        $maxDistanceKm = $this->proximityThresholdKm($trip);

        $match = TripCluster::query()
            ->where('start_governorate_id', $trip->start_governorate_id)
            ->where('end_governorate_id', $trip->end_governorate_id)
            ->where('time_window_start', '<=', $departure)
            ->where('time_window_end', '>=', $departure)
            ->get()
            ->map(function (TripCluster $cluster) use ($trip, $startPoint, $endPoint, $departure, $maxDistanceKm) {
                $startDistanceKm = $this->distanceKm(
                    (float) $startPoint->latitude,
                    (float) $startPoint->longitude,
                    (float) $cluster->reference_start_latitude,
                    (float) $cluster->reference_start_longitude
                );
                $endDistanceKm = $this->distanceKm(
                    (float) $endPoint->latitude,
                    (float) $endPoint->longitude,
                    (float) $cluster->reference_end_latitude,
                    (float) $cluster->reference_end_longitude
                );

                if ($startDistanceKm > $maxDistanceKm || $endDistanceKm > $maxDistanceKm) {
                    return null;
                }

                $timeDiffMinutes = abs($departure->diffInMinutes(Carbon::parse($cluster->reference_departure_time), false));

                return [
                    'cluster' => $cluster,
                    'score' => $startDistanceKm + $endDistanceKm + ($timeDiffMinutes / 60),
                ];
            })
            ->filter()
            ->sortBy('score')
            ->first();

        return $match['cluster'] ?? null;
    }

    private function createClusterForTrip(Trip $trip, TripPoint $startPoint, TripPoint $endPoint): TripCluster
    {
        $departure = Carbon::parse($trip->departure_time);
        $windowMinutes = $this->timeWindowMinutes($trip);

        return TripCluster::create([
            'reference_trip_id' => $trip->trip_id,
            'start_governorate_id' => $trip->start_governorate_id,
            'end_governorate_id' => $trip->end_governorate_id,
            'reference_start_latitude' => $startPoint->latitude,
            'reference_start_longitude' => $startPoint->longitude,
            'reference_end_latitude' => $endPoint->latitude,
            'reference_end_longitude' => $endPoint->longitude,
            'reference_departure_time' => $departure,
            'time_window_start' => $departure->copy()->subMinutes($windowMinutes),
            'time_window_end' => $departure->copy()->addMinutes($windowMinutes),
            'open_trips_limit' => self::OPEN_TRIPS_LIMIT,
        ]);
    }

    private function compareAvailability(Trip $left, Trip $right): int
    {
        return $this->availabilitySortKey($left) <=> $this->availabilitySortKey($right);
    }

    private function availabilitySortKey(Trip $trip): array
    {
        return [
            -1 * $this->activeSharedSeats($trip),
            optional($trip->departure_time)->timestamp ?? PHP_INT_MAX,
            -1 * (float) ($trip->driver?->user?->rating ?? 0),
            (float) ($trip->shared_price ?? PHP_INT_MAX),
            (int) $trip->trip_id,
        ];
    }

    private function activeSharedSeats(Trip $trip): int
    {
        return $trip->bookings
            ->filter(fn (Booking $booking) => $booking->booking_type === 'shared'
                && ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
            ->sum(fn (Booking $booking) => (int) $booking->seats_reserved);
    }

    private function resolvePoint(Trip $trip, string $type): ?TripPoint
    {
        return $trip->points
            ->first(fn (TripPoint $point) => $point->point_type === $type);
    }

    private function proximityThresholdKm(Trip $trip): float
    {
        return (int) $trip->start_governorate_id === (int) $trip->end_governorate_id
            ? self::LOCAL_PROXIMITY_KM
            : self::INTERCITY_PROXIMITY_KM;
    }

    private function timeWindowMinutes(Trip $trip): int
    {
        return (int) $trip->start_governorate_id === (int) $trip->end_governorate_id
            ? self::LOCAL_TIME_WINDOW_MINUTES
            : self::INTERCITY_TIME_WINDOW_MINUTES;
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
