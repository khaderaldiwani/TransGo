<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminTripManagementService
{
    public function __construct(
        private readonly TripTrackingService $tripTrackingService,
        private readonly TripClusterService $tripClusterService
    ) {
    }

    public function listTrips(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $query = $this->applyFilters($this->baseTripQuery(), $filters);

        if ($filters['delayed_only']) {
            $trips = $query->orderByDesc('departure_time')->get()->map(
                fn (Trip $trip) => $this->transformTripSummary($trip)
            )->filter(
                fn (array $trip) => $trip['delay']['is_delayed']
            )->values();

            return [
                'filters' => $filters,
                'summary' => $this->summary(),
                'items' => $trips,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $trips->count(),
                    'total' => $trips->count(),
                    'last_page' => 1,
                ],
            ];
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderByDesc('departure_time')->paginate($filters['per_page']);

        return [
            'filters' => $filters,
            'summary' => $this->summary(),
            'items' => $paginator->getCollection()->map(
                fn (Trip $trip) => $this->transformTripSummary($trip)
            )->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function getTripDetails(int $tripId): array
    {
        $trip = $this->baseTripQuery()
            ->with([
                'bookings.passenger',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.pickupPoint.governorate',
                'bookings.pickupPoint.tripPoint',
                'bookings.payments',
                'bookings.review',
            ])
            ->find($tripId);

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة.', 404);
        }

        return $this->transformTripDetails($trip);
    }

    public function activeTracking(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $activeStatusId = $this->resolveTripStatus(TripStatus::ACTIVE)->status_id;

        $items = $this->applyNonStatusFilters($this->baseTripQuery()->where('status_id', $activeStatusId), $filters)
            ->orderBy('departure_time')
            ->get()
            ->map(fn (Trip $trip) => $this->transformTrackingTrip($trip))
            ->values();

        $this->syncDelayNotifications($items);

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'active_count' => $items->count(),
                'delayed_count' => $items->where('delay.is_delayed', true)->count(),
            ],
            'items' => $items,
        ];
    }

    public function getTripTracking(int $tripId, int $historyLimit = 200): array
    {
        return $this->tripTrackingService->getAdminTripTracking($tripId, $historyLimit);
    }

    public function delayedTrips(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $filters['status'] = $filters['status'] ?: TripStatus::ACTIVE;
        $statusId = $this->resolveTripStatus($filters['status'])->status_id;

        $items = $this->applyNonStatusFilters($this->baseTripQuery()->where('status_id', $statusId), $filters)
            ->orderByDesc('departure_time')
            ->get()
            ->filter(fn (Trip $trip) => $this->isTripDelayed($trip))
            ->map(fn (Trip $trip) => $this->transformTrackingTrip($trip))
            ->values();

        $this->syncDelayNotifications($items);

        return [
            'generated_at' => now()->toIso8601String(),
            'threshold_minutes' => 60,
            'default_status' => $filters['status'],
            'count' => $items->count(),
            'items' => $items,
        ];
    }

    public function cancelTrip(int $tripId, ?string $reason, ?User $actor): array
    {
        $trip = $this->baseTripQuery()
            ->with(['bookings.passenger'])
            ->find($tripId);

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة.', 404);
        }

        $statusKey = data_get($trip, 'status.status_key');
        if (in_array($statusKey, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED, TripStatus::CANCELED], true)) {
            throw new RuntimeException('لا يمكن إلغاء رحلة منتهية أو ملغاة مسبقاً.', 422);
        }

        $canceledStatus = $this->resolveTripStatus(TripStatus::CANCELED);
        $reasonText = $reason ?: 'تم الإلغاء من قبل الإدارة.';

        DB::transaction(function () use ($trip, $canceledStatus, $reasonText, $actor) {
            $trip->forceFill([
                'status_id' => $canceledStatus->status_id,
            ])->save();

            $this->tripClusterService->refreshClusterAvailability($trip->cluster_id);

            $driverNotification = Notification::create([
                'title' => 'إلغاء إداري للرحلة',
                'body' => "تم إلغاء الرحلة رقم {$trip->trip_id}. السبب: {$reasonText}",
                'notification_type' => 'trip_canceled_admin',
                'reference_type' => 'trip',
                'reference_id' => $trip->trip_id,
                'created_by' => $actor?->user_id,
                'target_role' => Role::ROLE_DRIVER,
            ]);

            if ($trip->driver?->user_id) {
                UserNotification::firstOrCreate(
                    [
                        'notification_id' => $driverNotification->notification_id,
                        'user_id' => $trip->driver->user_id,
                    ],
                    [
                        'is_sent' => true,
                        'sent_at' => now(),
                    ]
                );
            }

            $passengerIds = $trip->bookings
                ->pluck('passenger_id')
                ->filter()
                ->unique()
                ->values();

            if ($passengerIds->isNotEmpty()) {
                $passengerNotification = Notification::create([
                    'title' => 'إلغاء الرحلة',
                    'body' => "تم إلغاء الرحلة رقم {$trip->trip_id}. السبب: {$reasonText}",
                    'notification_type' => 'trip_canceled_passengers',
                    'reference_type' => 'trip',
                    'reference_id' => $trip->trip_id,
                    'created_by' => $actor?->user_id,
                    'target_role' => Role::ROLE_PASSENGER,
                ]);

                foreach ($passengerIds as $passengerId) {
                    UserNotification::firstOrCreate(
                        [
                            'notification_id' => $passengerNotification->notification_id,
                            'user_id' => $passengerId,
                        ],
                        [
                            'is_sent' => true,
                            'sent_at' => now(),
                        ]
                    );
                }
            }
        });

        return $this->transformTripDetails(
            $this->baseTripQuery()
                ->with([
                    'bookings.passenger',
                    'bookings.status',
                    'bookings.attendanceStatus',
                    'bookings.pickupPoint.governorate',
                    'bookings.pickupPoint.tripPoint',
                    'bookings.payments',
                    'bookings.review',
                ])
                ->findOrFail($tripId)
        );
    }

    private function baseTripQuery(): Builder
    {
        return Trip::query()
            ->with([
                'status',
                'driver.user',
                'driver.vehicles.images',
                'startGovernorate',
                'endGovernorate',
                'points',
                'bookings.status',
            ])
            ->withCount('bookings');
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $search = $filters['search'];

            $query->where(function (Builder $innerQuery) use ($search) {
                $innerQuery->where('trip_id', $search)
                    ->orWhereHas('driver.user', function (Builder $driverQuery) use ($search) {
                        $driverQuery->where('full_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($filters['status'] !== '') {
            if ($filters['status'] === TripStatus::COMPLETED) {
                $statusIds = TripStatus::query()
                    ->whereIn('status_key', [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED])
                    ->pluck('status_id');

                $query->whereIn('status_id', $statusIds->all());
            } else {
                $statusId = TripStatus::query()
                    ->where('status_key', $filters['status'])
                    ->value('status_id');

                $query->where('status_id', $statusId ?? 0);
            }
        }

        return $this->applyNonStatusFilters($query, $filters);
    }

    private function applyNonStatusFilters(Builder $query, array $filters): Builder
    {
        if ($filters['driver_name'] !== '') {
            $driverName = $filters['driver_name'];

            $query->whereHas('driver.user', function (Builder $driverQuery) use ($driverName) {
                $driverQuery->where('full_name', 'like', "%{$driverName}%");
            });
        }

        if ($filters['departure_date'] !== '') {
            $query->whereDate('departure_time', $filters['departure_date']);
        }

        return $query;
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'driver_name' => trim((string) ($filters['driver_name'] ?? '')),
            'departure_date' => trim((string) ($filters['departure_date'] ?? '')),
            'delayed_only' => filter_var($filters['delayed_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'per_page' => max(1, min(100, (int) ($filters['per_page'] ?? 15))),
        ];
    }

    private function transformTripSummary(Trip $trip): array
    {
        $meta = $this->buildTripMeta($trip);
        $vehicle = $trip->driver?->vehicles->first();

        return [
            'trip_id' => $trip->trip_id,
            'status' => $this->statusPayload($trip),
            'departure' => [
                'at' => optional($trip->departure_time)->toIso8601String(),
                'from' => $trip->startGovernorate?->name,
                'to' => $trip->endGovernorate?->name,
            ],
            'trip_type' => $this->resolveTripType($trip),
            'vehicle' => [
                'image' => $vehicle?->images->first()?->image_url,
                'type' => $vehicle?->car_type,
                'seat_capacity' => $vehicle?->seat_capacity,
            ],
            'driver' => [
                'id' => $trip->driver?->user_id,
                'full_name' => $trip->driver?->user?->full_name,
                'phone' => $trip->driver?->user?->phone,
                'photo' => $trip->driver?->personal_photo,
                'rating' => $trip->driver?->user?->rating,
            ],
            'seats' => [
                'total' => (int) $trip->total_seats,
                'available' => (int) $trip->available_seats,
                'bookings_count' => (int) $trip->bookings_count,
            ],
            'expected_arrival_at' => $meta['expected_arrival_at'],
            'delay' => $meta['delay'],
            'actions' => [
                'details_endpoint' => "/api/v1/admin/trips/{$trip->trip_id}",
                'cancel_endpoint' => "/api/v1/admin/trips/{$trip->trip_id}/cancel",
            ],
        ];
    }

    private function transformTripDetails(Trip $trip): array
    {
        $meta = $this->buildTripMeta($trip);
        $tracking = $this->tripTrackingService->buildTrackingSnapshot($trip);
        $vehicle = $trip->driver?->vehicles->first();
        $driver=$trip->driver->first();
        $routePoints = $trip->points->values();

        return [
            'trip_id' => $trip->trip_id,
            'status' => $this->statusPayload($trip),
            'general' => [
                'departure_at' => optional($trip->departure_time)->toIso8601String(),
                'expected_arrival_at' => $meta['expected_arrival_at'],
                'estimated_duration_minutes' => (int) $trip->estimated_duration_minutes,
                'estimated_distance_km' => $trip->estimated_distance_km !== null ? (float) $trip->estimated_distance_km : null,
                'notes' => null,
            ],
            'vehicle' => [
                'type' => $vehicle?->car_type,
              //  'model' => $vehicle?->certified_agency,
                'id_card' => $driver?->id_card,
                'seats' => $vehicle?->seat_capacity,
                'amenities' => array_values(array_filter([
                    $trip->allow_shared ? 'حجز مشترك' : null,
                    $trip->allow_private ? 'حجز خاص' : null,
                    $vehicle?->insurance_image ? 'وثيقة تأمين' : null,
                    $vehicle?->mechanical_car ? 'فحص ميكانيكي' : null,
                ])),
                'image' => $vehicle?->images->first()?->image_url,
            ],
            'driver' => [
                'id' => $trip->driver?->user_id,
                'full_name' => $trip->driver?->user?->full_name,
                'photo' => $trip->driver?->personal_photo,
                'phone' => $trip->driver?->user?->phone,
                'profile' => [
                    'address' => $trip->driver?->address,
                    'approval_status' => $trip->driver?->approval_status,
                    'rating' => $trip->driver?->user?->rating,
                ],
            ],
            'route' => [
                'from' => $trip->startGovernorate?->name,
                'to' => $trip->endGovernorate?->name,
                'polyline' => $trip->route_polyline,
            //    'progress_percent' => $meta['progress_percent'],
                'points' => $routePoints->map(function ($point, $index) use ($meta) {
                    return [
                        'point_id' => $point->point_id,
                        'type' => $point->point_type,
                        'address' => $point->address,
                        'latitude' => (float) $point->latitude,
                        'longitude' => (float) $point->longitude,
                        'sequence_order' => (int) $point->sequence_order,
                        'expected_arrival_at' => $meta['point_eta'][$index] ?? null,
                    ];
                })->values(),
            ],
            'booking_info' => [
                'remaining_seats' => (int) $trip->available_seats,
                'bookings_count' => (int) $trip->bookings_count,
                'trip_type' => $this->resolveTripType($trip),
                'bookings' => $trip->bookings->map(function ($booking) {
                    $payment = $booking->payments->sortByDesc('payment_id')->first();

                    return [
                        'booking_id' => $booking->booking_id,
                        'booking_code' => $booking->booking_code,
                        'booking_type' => $booking->booking_type,
                        'seats_reserved' => (int) $booking->seats_reserved,
                        'status' => [
                            'key' => $booking->status?->status_key,
                            'name' => $booking->status?->status_name,
                        ],
                        'passenger' => [
                        'id' => $booking->passenger?->user_id,
                        'full_name' => $booking->passenger?->full_name,
                        'phone' => $booking->passenger?->phone,
                        'image' => $booking->passenger?->profile_photo,
                        'rating' => $booking->passenger?->rating !== null ? (float) $booking->passenger->rating : null,
                    ],
                        'pickup_point' => [
                            'point_name' => $booking->pickupPoint?->point_name,
                            'address' => $booking->pickupPoint?->address,
                            'meeting_time' => optional($booking->pickupPoint?->meeting_time)->toIso8601String(),
                            'latitude' => $booking->pickupPoint?->latitude !== null ? (float) $booking->pickupPoint?->latitude : null,
                            'longitude' => $booking->pickupPoint?->longitude !== null ? (float) $booking->pickupPoint?->longitude : null,
                            'governorate' => $booking->pickupPoint?->governorate?->name,
                        ],
                        'payment' => [
                            'method' => $payment?->payment_method ?? $booking->payment_method,
                            'status' => $payment?->payment_status,
                            'amount' => $payment?->amount !== null ? (float) $payment->amount : (float) $booking->total_amount,
                        ],
                        'attendance' => [
                            'status' => $booking->attendanceStatus?->status_name,
                        ],
                        'review' => [
                            'rating' => $booking->review?->rating,
                            'comment' => $booking->review?->comment,
                        ],
                        'notes' => $booking->notes,
                    ];
                })->values(),
            ],
            'monitoring' => [
                'is_delayed' => $meta['delay']['is_delayed'],
                'delay_minutes' => $meta['delay']['minutes'],
                'is_tracking_active' => $tracking['is_tracking_active'],
                'has_live_location' => $tracking['has_live_location'],
                'last_location_at' => $tracking['last_location_at'],
                'current_position' => $tracking['last_position'],
                'active_tracking_endpoint' => '/api/v1/admin/trips/tracking/active',
                'trip_tracking_endpoint' => "/api/v1/admin/trips/{$trip->trip_id}/tracking",
            ],
            'actions' => [
                'cancel_endpoint' => "/api/v1/admin/trips/{$trip->trip_id}/cancel",
            ],
        ];
    }

    private function transformTrackingTrip(Trip $trip): array
    {
        $meta = $this->buildTripMeta($trip);
        $tracking = $this->tripTrackingService->buildTrackingSnapshot($trip);

        return [
            'trip_id' => $trip->trip_id,
            'driver_name' => $trip->driver?->user?->full_name,
            'status' => $this->statusPayload($trip),
            'departure_at' => optional($trip->departure_time)->toIso8601String(),
            'expected_arrival_at' => $meta['expected_arrival_at'],
            'delay' => $meta['delay'],
            'progress_percent' => $meta['progress_percent'],
            'is_tracking_active' => $tracking['is_tracking_active'],
            'has_live_location' => $tracking['has_live_location'],
            'last_location_at' => $tracking['last_location_at'],
            'current_position' => $tracking['last_position'],
            'route' => [
                'from' => $trip->startGovernorate?->name,
                'to' => $trip->endGovernorate?->name,
                'points' => $trip->points->map(fn ($point) => [
                    'point_id' => $point->point_id,
                    'type' => $point->point_type,
                    'address' => $point->address,
                    'latitude' => (float) $point->latitude,
                    'longitude' => (float) $point->longitude,
                ])->values(),
            ],
            'tracking_endpoint' => "/api/v1/admin/trips/{$trip->trip_id}/tracking",
        ];
    }

    private function buildTripMeta(Trip $trip): array
    {
        $departure = $trip->departure_time ? Carbon::parse($trip->departure_time) : null;
        $expectedArrival = $departure?->copy()->addMinutes((int) $trip->estimated_duration_minutes);
        $delayMinutes = $this->resolveDelayMinutes($trip, $expectedArrival);
        $points = $trip->points->values();
        $progress = $this->resolveProgressPercent($trip, $departure, $expectedArrival);

        return [
            'expected_arrival_at' => $expectedArrival?->toIso8601String(),
            'delay' => [
                'minutes' => $delayMinutes,
                'is_delayed' => $delayMinutes >= 60,
            ],
            'progress_percent' => $progress,
            'current_position' => $this->resolveCurrentPosition($points, $progress),
            'point_eta' => $this->resolvePointEta($points, $departure, (int) $trip->estimated_duration_minutes),
        ];
    }

    private function isTripDelayed(Trip $trip): bool
    {
        if (! $trip->departure_time) {
            return false;
        }

        $expectedArrival = Carbon::parse($trip->departure_time)
            ->addMinutes((int) $trip->estimated_duration_minutes);

        return now()->greaterThan($expectedArrival->copy()->addMinutes(60));
    }

    private function resolveDelayMinutes(Trip $trip, ?Carbon $expectedArrival): int
    {
        if (! $expectedArrival) {
            return 0;
        }

        if (now()->lte($expectedArrival)) {
            return 0;
        }

        return max(0, $expectedArrival->diffInMinutes(now(), false));
    }

    private function resolveProgressPercent(Trip $trip, ?Carbon $departure, ?Carbon $expectedArrival): int
    {
        if (! $departure || ! $expectedArrival || data_get($trip, 'status.status_key') === TripStatus::PENDING) {
            return 0;
        }

        if (now()->lte($departure)) {
            return 0;
        }

        $totalSeconds = max(1, $expectedArrival->diffInSeconds($departure));
        $elapsedSeconds = min($totalSeconds, max(0, $departure->diffInSeconds(now(), false)));

        return (int) round(($elapsedSeconds / $totalSeconds) * 100);
    }

    private function resolveCurrentPosition(Collection $points, int $progress): ?array
    {
        if ($points->isEmpty()) {
            return null;
        }

        if ($points->count() === 1) {
            return [
                'latitude' => (float) $points->first()->latitude,
                'longitude' => (float) $points->first()->longitude,
            ];
        }

        $segmentPosition = (max(0, min(100, $progress)) / 100) * ($points->count() - 1);
        $segmentIndex = (int) floor($segmentPosition);
        $segmentRatio = $segmentPosition - $segmentIndex;
        $start = $points[$segmentIndex];
        $end = $points[min($segmentIndex + 1, $points->count() - 1)];

        return [
            'latitude' => round(((float) $start->latitude) + ((((float) $end->latitude) - ((float) $start->latitude)) * $segmentRatio), 7),
            'longitude' => round(((float) $start->longitude) + ((((float) $end->longitude) - ((float) $start->longitude)) * $segmentRatio), 7),
        ];
    }

    private function resolvePointEta(Collection $points, ?Carbon $departure, int $durationMinutes): array
    {
        if (! $departure || $points->isEmpty()) {
            return [];
        }

        $steps = max(1, $points->count() - 1);

        return $points->values()->map(function ($point, $index) use ($departure, $durationMinutes, $steps) {
            $ratio = $steps === 0 ? 0 : $index / $steps;
            return $departure->copy()->addMinutes((int) round($durationMinutes * $ratio))->toIso8601String();
        })->all();
    }

    private function statusPayload(Trip $trip): array
    {
        return [
            'key' => $trip->status?->status_key,
            'name' => $trip->status?->status_name,
            'color' => match ($trip->status?->status_key) {
                TripStatus::PENDING => '#f59e0b',
                TripStatus::ACTIVE => '#0ea5e9',
                TripStatus::COMPLETED => '#10b981',
                TripStatus::AUTO_COMPLETED => '#14b8a6',
                TripStatus::CANCELED => '#ef4444',
                default => '#64748b',
            },
        ];
    }

    private function resolveTripType(Trip $trip): string
    {
        if ($trip->allow_shared && $trip->allow_private) {
            return 'both';
        }

        if ($trip->allow_private) {
            return 'private';
        }

        return 'shared';
    }

    private function resolveTripStatus(string $statusKey): TripStatus
    {
        $status = TripStatus::query()
            ->where('status_key', $statusKey)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw new RuntimeException('حالة الرحلة المطلوبة غير موجودة.', 500);
        }

        return $status;
    }

    private function summary(): array
    {
        $statusCounts = Trip::query()
            ->join('trip_statuses', 'trip_statuses.status_id', '=', 'trips.status_id')
            ->selectRaw('trip_statuses.status_key, COUNT(*) as aggregate')
            ->groupBy('trip_statuses.status_key')
            ->pluck('aggregate', 'status_key');

        $delayedCount = $this->baseTripQuery()
            ->whereHas('status', fn (Builder $query) => $query->where('status_key', TripStatus::ACTIVE))
            ->get()
            ->map(fn (Trip $trip) => $this->buildTripMeta($trip))
            ->filter(fn (array $meta) => $meta['delay']['is_delayed'])
            ->count();

        return [
            'all' => Trip::count(),
            'pending' => (int) ($statusCounts[TripStatus::PENDING] ?? 0),
            'active' => (int) ($statusCounts[TripStatus::ACTIVE] ?? 0),
            'completed' => (int) (($statusCounts[TripStatus::COMPLETED] ?? 0) + ($statusCounts[TripStatus::AUTO_COMPLETED] ?? 0)),
            'canceled' => (int) ($statusCounts[TripStatus::CANCELED] ?? 0),
            'delayed' => $delayedCount,
        ];
    }

    private function syncDelayNotifications(Collection $trackingItems): void
    {
        $recipients = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', [Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE]))
            ->pluck('user_id');

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($trackingItems as $trip) {
            if (! data_get($trip, 'delay.is_delayed')) {
                continue;
            }

            $existing = Notification::query()
                ->where('notification_type', 'trip_delay_alert')
                ->where('reference_type', 'trip')
                ->where('reference_id', data_get($trip, 'trip_id'))
                ->where('created_at', '>=', now()->subHour())
                ->exists();

            if ($existing) {
                continue;
            }

            $notification = Notification::create([
                'title' => 'تنبيه تأخر رحلة',
                'body' => 'الرحلة رقم '.data_get($trip, 'trip_id').' للسائق '.data_get($trip, 'driver_name').' متأخرة بنحو '.data_get($trip, 'delay.minutes').' دقيقة.',
                'notification_type' => 'trip_delay_alert',
                'reference_type' => 'trip',
                'reference_id' => data_get($trip, 'trip_id'),
                'target_role' => Role::ROLE_ADMIN,
            ]);

            foreach ($recipients as $userId) {
                UserNotification::firstOrCreate(
                    [
                        'notification_id' => $notification->notification_id,
                        'user_id' => $userId,
                    ],
                    [
                        'is_sent' => true,
                        'sent_at' => now(),
                    ]
                );
            }
        }
    }
}
