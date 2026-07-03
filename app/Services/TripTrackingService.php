<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripLiveLocation;
use App\Models\TripPoint;
use App\Models\TripPointNotificationLog;
use App\Models\TripStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TripTrackingService
{
    private const APPROACH_DISTANCE_KM = 5.0;
    private const ARRIVAL_DISTANCE_KM = 0.15;

    public function __construct(
        private readonly NotificationDispatchService $notifications,
        private readonly TripTrackingPerformanceService $trackingPerformanceService
    ) {
    }

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

        $this->notifyRoutePointProgress($tripId, $actor, $storedLocation);

        return $data;
    }

    public function getDriverTripTracking(int $tripId, User $actor, int $historyLimit = 100): array
    {
        $trip = $this->baseTrackingTripQuery()
            ->where('trip_id', $tripId)
            ->where('driver_id', $actor->user_id)
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
            $trip->setRelation(
                'liveLocations',
                $this->trackingPerformanceService->recentHistoryForTrip(
                    (int) $trip->trip_id,
                    $this->normalizeHistoryLimit($historyLimit)
                )
            );
        }

        $items = $trip->liveLocations
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

    private function notifyRoutePointProgress(int $tripId, User $actor, TripLiveLocation $location): void
    {
        $trip = Trip::query()
            ->with([
                'points',
                'bookings.status',
                'bookings.pickupPoint',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            return;
        }

        $points = $trip->points
            ->filter(fn (TripPoint $point) => $point->point_type !== 'start')
            ->values();

        foreach ($points as $point) {
            $distanceKm = $this->distanceKm(
                (float) $location->latitude,
                (float) $location->longitude,
                (float) $point->latitude,
                (float) $point->longitude
            );

            if ($distanceKm <= self::APPROACH_DISTANCE_KM) {
                $this->notifyPointOnce($trip, $point, $actor, 'trip_point_approaching', $distanceKm);
            }

            if ($distanceKm <= self::ARRIVAL_DISTANCE_KM) {
                $this->notifyPointOnce($trip, $point, $actor, 'trip_point_arrived', $distanceKm);
            }
        }
    }

    private function notifyPointOnce(
        Trip $trip,
        TripPoint $point,
        User $actor,
        string $notificationType,
        float $distanceKm
    ): void {
        $log = TripPointNotificationLog::firstOrCreate(
            [
                'trip_id' => $trip->trip_id,
                'point_id' => $point->point_id,
                'notification_type' => $notificationType,
            ],
            [
                'triggered_at' => now(),
            ]
        );

        if (! $log->wasRecentlyCreated) {
            return;
        }

        $isArrival = $notificationType === 'trip_point_arrived';
        $pointName = $point->address ?: $point->note ?: 'نقطة التوقف';
        $title = $isArrival ? 'تم الوصول إلى نقطة توقف' : 'اقتربت الرحلة من نقطة توقف';
        $body = $isArrival
            ? "وصلت الرحلة رقم {$trip->trip_id} إلى {$pointName}."
            : "اقتربت الرحلة رقم {$trip->trip_id} من {$pointName} بمسافة أقل من 5 كم.";

        $notification = Notification::create([
            'title' => $title,
            'body' => $body,
            'notification_type' => $notificationType,
            'reference_type' => 'trip_point',
            'reference_id' => $point->point_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
        ]);

        $passengerIds = $trip->bookings
            ->filter(fn (Booking $booking) => ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
            ->filter(fn (Booking $booking) => (int) ($booking->pickupPoint?->trip_point_id ?? 0) === (int) $point->point_id)
            ->pluck('passenger_id')
            ->filter()
            ->unique()
            ->values();

        if ($passengerIds->isEmpty()) {
            $passengerIds = $trip->bookings
                ->filter(fn (Booking $booking) => ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
                ->pluck('passenger_id')
                ->filter()
                ->unique()
                ->values();
        }

        $data = [
            'trip_id' => $trip->trip_id,
            'point_id' => $point->point_id,
            'point_type' => $point->point_type,
            'distance_km' => round($distanceKm, 3),
        ];

        $this->notifications->sendExistingToUsers($notification, $passengerIds, $data);

        if ($trip->driver_id) {
            $driverNotification = Notification::create([
                'title' => $title,
                'body' => $body,
                'notification_type' => 'driver_'.$notificationType,
                'reference_type' => 'trip_point',
                'reference_id' => $point->point_id,
                'created_by' => $actor->user_id,
                'target_role' => Role::ROLE_DRIVER,
            ]);

            $this->notifications->sendExistingToUser($driverNotification, $trip->driver_id, $data);
        }
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
