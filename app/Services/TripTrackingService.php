<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripLiveLocation;
use App\Models\TripStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TripTrackingService
{
    public function activateTracking(Trip $trip, ?Carbon $startedAt = null): void
    {
        $trip->forceFill([
            'is_tracking_active' => true,
            'tracking_started_at' => $startedAt ?? now(),
            'tracking_stopped_at' => null,
        ])->save();
    }

    public function stopTracking(
        Trip $trip,
        ?Carbon $stoppedAt = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): void {
        $stoppedAt ??= now();

        $attributes = [
            'is_tracking_active' => false,
            'tracking_stopped_at' => $stoppedAt,
        ];

        if ($latitude !== null && $longitude !== null) {
            $attributes['last_latitude'] = $latitude;
            $attributes['last_longitude'] = $longitude;
            $attributes['last_location_at'] = $stoppedAt;
        }

        $trip->forceFill($attributes)->save();
    }

    public function recordDriverLocation(int $tripId, User $actor, array $payload): array
    {
        $trip = $this->baseTrackingTripQuery()
            ->where('trip_id', $tripId)
            ->where('driver_id', $actor->user_id)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        $this->ensureTrackingCanAcceptLocations($trip);

        $recordedAt = isset($payload['recorded_at'])
            ? Carbon::parse($payload['recorded_at'])
            : now();

        $storedLocation = DB::transaction(function () use ($trip, $actor, $payload, $recordedAt) {
            $lockedTrip = Trip::query()
                ->where('trip_id', $trip->trip_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureTrackingCanAcceptLocations($lockedTrip);

            $location = TripLiveLocation::create([
                'trip_id' => $lockedTrip->trip_id,
                'driver_id' => $actor->user_id,
                'latitude' => round((float) $payload['latitude'], 7),
                'longitude' => round((float) $payload['longitude'], 7),
                'speed_kmh' => $payload['speed_kmh'] ?? null,
                'heading' => $payload['heading'] ?? null,
                'accuracy_meters' => $payload['accuracy_meters'] ?? null,
                'recorded_at' => $recordedAt,
            ]);

            $this->syncLatestSnapshot($lockedTrip, $location);

            return $location;
        });

        $data = $this->getDriverTripTracking($tripId, $actor, 100);
        $data['stored_location_id'] = $storedLocation->location_id;

        return $data;
    }

    public function getDriverTripTracking(int $tripId, User $actor, int $historyLimit = 100): array
    {
        $trip = $this->baseTrackingTripQuery()
            ->where('trip_id', $tripId)
            ->where('driver_id', $actor->user_id)
            ->with([
                'liveLocations' => fn ($query) => $query
                    ->latest('recorded_at')
                    ->limit($this->normalizeHistoryLimit($historyLimit)),
            ])
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        return $this->buildTrackingPayload($trip, $this->normalizeHistoryLimit($historyLimit), 'driver');
    }

    public function getAdminTripTracking(int $tripId, int $historyLimit = 200): array
    {
        $trip = $this->baseTrackingTripQuery()
            ->where('trip_id', $tripId)
            ->with([
                'liveLocations' => fn ($query) => $query
                    ->latest('recorded_at')
                    ->limit($this->normalizeHistoryLimit($historyLimit)),
            ])
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة.', 404);
        }

        return $this->buildTrackingPayload($trip, $this->normalizeHistoryLimit($historyLimit), 'admin');
    }

    public function buildTrackingSnapshot(Trip $trip): array
    {
        return [
            'is_tracking_active' => (bool) $trip->is_tracking_active,
            'tracking_started_at' => optional($trip->tracking_started_at)->toIso8601String(),
            'tracking_stopped_at' => optional($trip->tracking_stopped_at)->toIso8601String(),
            'last_location_at' => optional($trip->last_location_at)->toIso8601String(),
            'has_live_location' => $trip->last_latitude !== null && $trip->last_longitude !== null,
            'last_position' => $trip->last_latitude !== null && $trip->last_longitude !== null
                ? [
                    'latitude' => (float) $trip->last_latitude,
                    'longitude' => (float) $trip->last_longitude,
                    'speed_kmh' => $trip->last_speed_kmh !== null ? (float) $trip->last_speed_kmh : null,
                    'heading' => $trip->last_heading !== null ? (float) $trip->last_heading : null,
                    'accuracy_meters' => $trip->last_accuracy_meters !== null ? (float) $trip->last_accuracy_meters : null,
                ]
                : null,
        ];
    }

    public function buildTrackingHistory(Trip $trip, int $historyLimit = 100): array
    {
        if (! $trip->relationLoaded('liveLocations')) {
            $trip->load([
                'liveLocations' => fn ($query) => $query
                    ->latest('recorded_at')
                    ->limit($this->normalizeHistoryLimit($historyLimit)),
            ]);
        }

        $items = $trip->liveLocations
            ->sortBy('recorded_at')
            ->values()
            ->map(function (TripLiveLocation $location) {
                return [
                    'location_id' => $location->location_id,
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                    'speed_kmh' => $location->speed_kmh !== null ? (float) $location->speed_kmh : null,
                    'heading' => $location->heading !== null ? (float) $location->heading : null,
                    'accuracy_meters' => $location->accuracy_meters !== null ? (float) $location->accuracy_meters : null,
                    'recorded_at' => optional($location->recorded_at)->toIso8601String(),
                ];
            });

        return [
            'count' => $items->count(),
            'items' => $items,
        ];
    }

    private function buildTrackingPayload(Trip $trip, int $historyLimit, string $context): array
    {
        $snapshot = $this->buildTrackingSnapshot($trip);
        $history = $this->buildTrackingHistory($trip, $historyLimit);
        $routePoints = $trip->points->map(fn ($point) => [
            'point_id' => $point->point_id,
            'type' => $point->point_type,
            'address' => $point->address,
            'latitude' => (float) $point->latitude,
            'longitude' => (float) $point->longitude,
            'sequence_order' => (int) $point->sequence_order,
        ])->values();

        $basePath = $context === 'admin'
            ? "/api/v1/admin/trips/{$trip->trip_id}/tracking"
            : "/api/v1/driver/trips/{$trip->trip_id}/tracking";

        return [
            'trip_id' => $trip->trip_id,
            'status' => [
                'key' => $trip->status?->status_key,
                'name' => $trip->status?->status_name,
            ],
            'driver' => [
                'id' => $trip->driver?->user_id,
                'full_name' => $trip->driver?->user?->full_name,
                'phone' => $trip->driver?->user?->phone,
            ],
            'trip' => [
                'departure_at' => optional($trip->departure_time)->toIso8601String(),
                'actual_start_time' => optional($trip->actual_start_time)->toIso8601String(),
                'completed_at' => optional($trip->completed_at)->toIso8601String(),
                'from' => $trip->startGovernorate?->name,
                'to' => $trip->endGovernorate?->name,
                'route_polyline' => $trip->route_polyline,
            ],
            'tracking' => [
                ...$snapshot,
                'history_limit' => $historyLimit,
                'history' => $history,
                'route' => [
                    'points' => $routePoints,
                ],
                'details_endpoint' => $basePath,
                'location_update_endpoint' => $context === 'driver'
                    ? "/api/v1/driver/trips/{$trip->trip_id}/location"
                    : null,
            ],
        ];
    }

    private function syncLatestSnapshot(Trip $trip, TripLiveLocation $location): void
    {
        $shouldUpdate = ! $trip->last_location_at
            || Carbon::parse($location->recorded_at)->greaterThanOrEqualTo(Carbon::parse($trip->last_location_at));

        if (! $shouldUpdate) {
            return;
        }

        $trip->forceFill([
            'last_latitude' => $location->latitude,
            'last_longitude' => $location->longitude,
            'last_speed_kmh' => $location->speed_kmh,
            'last_heading' => $location->heading,
            'last_accuracy_meters' => $location->accuracy_meters,
            'last_location_at' => $location->recorded_at,
        ])->save();
    }

    private function ensureTrackingCanAcceptLocations(Trip $trip): void
    {
        if ($trip->status?->status_key !== TripStatus::ACTIVE) {
            throw new RuntimeException('لا يمكن استقبال مواقع حية لرحلة غير نشطة.', 422);
        }

        if (! $trip->is_tracking_active) {
            throw new RuntimeException('التتبع اللحظي غير مفعل لهذه الرحلة.', 422);
        }
    }

    private function normalizeHistoryLimit(?int $historyLimit): int
    {
        return max(1, min(500, (int) ($historyLimit ?? 100)));
    }

    private function baseTrackingTripQuery(): Builder
    {
        return Trip::query()
            ->with([
                'status',
                'driver.user',
                'startGovernorate',
                'endGovernorate',
                'points',
            ]);
    }
}
