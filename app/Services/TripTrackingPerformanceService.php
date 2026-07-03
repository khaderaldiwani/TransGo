<?php

namespace App\Services;

use App\Models\TripLiveLocation;
use Illuminate\Support\Collection;

class TripTrackingPerformanceService
{
    public function recentHistoryForTrip(int $tripId, int $limit): Collection
    {
        return TripLiveLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->sortBy('recorded_at')
            ->values();
    }

    public function latestLocationForTrip(int $tripId): ?TripLiveLocation
    {
        return TripLiveLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->first();
    }
}
