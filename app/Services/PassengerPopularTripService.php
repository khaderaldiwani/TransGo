<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripStatus;
use Illuminate\Support\Collection;

class PassengerPopularTripService
{
    public function __construct(private readonly PassengerTripCardService $tripCardService)
    {
    }

    public function list(int $limit = 5): array
    {
        $limit = max(1, min($limit, 5));

        $trips = $this->queryPopularTrips($limit);

        return [
            'items' => $trips
                ->map(fn (Trip $trip) => $this->tripCardService->transform($trip, $this->requestedTripType($trip)))
                ->values(),
            'meta' => [
                'total' => $trips->count(),
                'limit' => $limit,
            ],
        ];
    }

    private function queryPopularTrips(int $limit): Collection
    {
        return Trip::query()
            ->select('trips.*')
            ->selectSub(function ($query) {
                $query->from('trips as completed_driver_trips')
                    ->join('trip_statuses as completed_statuses', 'completed_statuses.status_id', '=', 'completed_driver_trips.status_id')
                    ->whereColumn('completed_driver_trips.driver_id', 'trips.driver_id')
                    ->whereIn('completed_statuses.status_key', [
                        TripStatus::COMPLETED,
                        TripStatus::AUTO_COMPLETED,
                    ])
                    ->selectRaw('COUNT(*)');
            }, 'driver_completed_trips_count')
            ->join('users as driver_users', 'driver_users.user_id', '=', 'trips.driver_id')
            ->with([
                'status',
                'points',
                'driver.user',
                'driver.vehicles.category',
                'driver.vehicles.images',
                'startGovernorate',
                'endGovernorate',
                'cluster',
            ])
            ->where('trips.departure_time', '>=', now())
            ->whereHas('status', function ($builder) {
                $builder->whereIn('status_key', [TripStatus::PENDING, TripStatus::ACTIVE]);
            })
            ->where(function ($builder) {
                $builder->where(function ($shared) {
                    $shared->where('trips.allow_shared', true)
                        ->where('trips.is_private_booked', false)
                        ->where('trips.is_booking_visible', true)
                        ->where('trips.available_seats', '>', 0);
                })->orWhere(function ($private) {
                    $private->where('trips.allow_private', true)
                        ->where('trips.is_private_booked', false);
                });
            })
            ->orderByDesc('driver_completed_trips_count')
            ->orderByDesc('driver_users.rating')
            ->orderBy('trips.departure_time')
            ->orderBy('trips.trip_id')
            ->limit($limit)
            ->get();
    }

    private function requestedTripType(Trip $trip): string
    {
        if ((bool) $trip->allow_shared && (bool) $trip->is_booking_visible && ! (bool) $trip->is_private_booked && (int) $trip->available_seats > 0) {
            return 'shared';
        }

        return 'private';
    }
}
