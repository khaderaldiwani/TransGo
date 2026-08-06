<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\TripTrackingShare;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class TripTrackingShareService
{
    public function __construct(private readonly TripTrackingService $tripTrackingService)
    {
    }

    public function createShare(int $tripId, User $actor, ?int $expiresInMinutes = null): array
    {
        $booking = Booking::query()
            ->with(['status', 'trip.status'])
            ->where('trip_id', $tripId)
            ->where('passenger_id', $actor->user_id)
            ->latest('booking_id')
            ->first();

        if (! $booking || in_array($booking->status?->status_key, ['canceled', 'rejected'], true)) {
            throw new RuntimeException('لا يمكن مشاركة تتبع هذه الرحلة لأن الراكب غير مشترك بها.', 404);
        }

        if (! $booking->trip instanceof Trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة.', 404);
        }

        $expiresAt = now()->addMinutes($expiresInMinutes ?? 1440);

        $share = TripTrackingShare::query()->create([
            'trip_id' => $booking->trip_id,
            'booking_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'token' => $this->generateToken(),
            'expires_at' => $expiresAt,
        ]);

        return $this->transformShare($share);
    }

    public function showPublicTracking(string $token, int $historyLimit = 100): array
    {
        $share = TripTrackingShare::query()
            ->with([
                'trip.status',
                'trip.driver.user',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'booking.status',
            ])
            ->where('token', $token)
            ->first();

        if (! $share) {
            throw new RuntimeException('رابط التتبع غير موجود.', 404);
        }

        if ($share->revoked_at !== null) {
            throw new RuntimeException('تم إلغاء رابط التتبع.', 410);
        }

        if ($share->expires_at !== null && $share->expires_at->isPast()) {
            throw new RuntimeException('انتهت صلاحية رابط التتبع.', 410);
        }

        if (in_array($share->booking?->status?->status_key, ['canceled', 'rejected'], true)) {
            throw new RuntimeException('لم يعد رابط التتبع متاحًا لهذا الحجز.', 410);
        }

        $trip = $share->trip;

        if (! $trip instanceof Trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة.', 404);
        }

        $share->forceFill(['last_accessed_at' => now()])->save();

        $trackingAvailable = $trip->status?->status_key === TripStatus::ACTIVE
            && (bool) $trip->is_tracking_active;

        if (! $trackingAvailable) {
            return $this->inactiveTrackingPayload($share, $trip);
        }

        $tracking = $this->tripTrackingService->getAdminTripTracking($trip->trip_id, $historyLimit);

        return [
            'share' => [
                'token' => $share->token,
                'expires_at' => optional($share->expires_at)->toIso8601String(),
            ],
            'trip_id' => $trip->trip_id,
            'tracking_available' => true,
            'trip' => [
                'departure_at' => data_get($tracking, 'trip.departure_at'),
                'actual_start_time' => data_get($tracking, 'trip.actual_start_time'),
                'from' => data_get($tracking, 'trip.from'),
                'to' => data_get($tracking, 'trip.to'),
                'route_polyline' => data_get($tracking, 'trip.route_polyline'),
            ],
            'driver' => [
                'id' => data_get($tracking, 'driver.id'),
                'full_name' => data_get($tracking, 'driver.full_name'),
            ],
            'tracking' => $this->publicTrackingData((array) data_get($tracking, 'tracking', []), $share->token),
        ];
    }

    private function inactiveTrackingPayload(TripTrackingShare $share, Trip $trip): array
    {
        return [
            'share' => [
                'token' => $share->token,
                'expires_at' => optional($share->expires_at)->toIso8601String(),
            ],
            'trip_id' => $trip->trip_id,
            'tracking_available' => false,
            'tracking_enabled_after_start' => true,
            'status' => [
                'key' => $trip->status?->status_key,
                'name' => $trip->status?->status_name,
            ],
            'trip' => [
                'departure_at' => \App\Support\ApiDateTime::toAppIso($trip->departure_time),
                'from' => $trip->startGovernorate?->name,
                'to' => $trip->endGovernorate?->name,
                'route_polyline' => $trip->route_polyline,
            ],
            'driver' => [
                'id' => $trip->driver?->user_id,
                'full_name' => $trip->driver?->user?->full_name,
            ],
            'tracking' => [
                'is_tracking_active' => (bool) $trip->is_tracking_active,
                'tracking_started_at' => optional($trip->tracking_started_at)->toIso8601String(),
                'tracking_stopped_at' => optional($trip->tracking_stopped_at)->toIso8601String(),
                'last_location_at' => optional($trip->last_location_at)->toIso8601String(),
                'has_live_location' => $trip->last_latitude !== null && $trip->last_longitude !== null,
                'last_position' => $trip->last_latitude !== null && $trip->last_longitude !== null
                    ? [
                        'latitude' => (float) $trip->last_latitude,
                        'longitude' => (float) $trip->last_longitude,
                    ]
                    : null,
                'details_endpoint' => "/api/v1/public/tracking/{$share->token}",
            ],
            'message' => 'يتم إتاحة التتبع بعد بدء الرحلة فقط.',
        ];
    }

    private function publicTrackingData(array $tracking, string $token): array
    {
        return [
            'is_tracking_active' => (bool) ($tracking['is_tracking_active'] ?? false),
            'tracking_started_at' => $tracking['tracking_started_at'] ?? null,
            'tracking_stopped_at' => $tracking['tracking_stopped_at'] ?? null,
            'last_location_at' => $tracking['last_location_at'] ?? null,
            'has_live_location' => (bool) ($tracking['has_live_location'] ?? false),
            'last_position' => $tracking['last_position'] ?? null,
            'history_limit' => $tracking['history_limit'] ?? null,
            'history' => $tracking['history'] ?? ['count' => 0, 'items' => []],
            'route' => $tracking['route'] ?? ['points' => []],
            'details_endpoint' => "/api/v1/public/tracking/{$token}",
        ];
    }

    private function transformShare(TripTrackingShare $share): array
    {
        $publicPath = "/tracking/share/{$share->token}";

        return [
            'share_id' => $share->share_id,
            'trip_id' => $share->trip_id,
            'booking_id' => $share->booking_id,
            'token' => $share->token,
            'share_url' => rtrim((string) config('app.tracking_web_url'), '/').$publicPath,
            'public_path' => $publicPath,
            'api_endpoint' => "/api/v1/public/tracking/{$share->token}",
            'expires_at' => optional($share->expires_at)->toIso8601String(),
        ];
    }

    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (TripTrackingShare::query()->where('token', $token)->exists());

        return $token;
    }
}
