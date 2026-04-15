<?php

namespace App\Services;

use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BookingService
{
    private const MAX_BOOKINGS_PER_DAY = 6;
    private const MAX_PICKUP_DISTANCE_METERS = 300.0;

    public function __construct(
        private readonly GovernorateResolverService $governorateResolverService
    ) {
    }

    public function createBooking(array $data, User $actor): Booking
    {
        return DB::transaction(function () use ($data, $actor) {
            $trip = Trip::query()
                ->lockForUpdate()
                ->find($data['trip_id']);

            if (! $trip) {
                throw ValidationException::withMessages([
                    'trip_id' => 'الرحلة المحددة غير موجودة.',
                ]);
            }

            $trip->load(['points', 'driver.user', 'status', 'bookings.status']);

            $this->ensureTripIsBookable($trip);
            $this->ensureDailyBookingLimit($actor);

            $bookingType = (string) $data['booking_type'];
            $seatsReserved = $this->normalizeSeatsReserved($bookingType, $data['seats_reserved'] ?? null, $trip);
            $this->ensureTripModeCompatibility($trip, $bookingType);

            if ($bookingType === 'shared' && $seatsReserved > $trip->available_seats) {
                throw ValidationException::withMessages([
                    'seats_reserved' => 'عدد المقاعد المطلوبة أكبر من المقاعد المتاحة.',
                ]);
            }

            $pickupPointPayload = $this->buildPickupPointPayload($trip, $data['pickup_point']);
            $totalAmount = $this->calculateTotalAmount($trip, $bookingType, $seatsReserved);
            $isWalletPayment = $data['payment_method'] === 'electronic';
            $wallet = $isWalletPayment
                ? Wallet::query()->where('user_id', $actor->user_id)->lockForUpdate()->first()
                : null;

            if ($isWalletPayment) {
                $this->ensureWalletCanPay($wallet, $totalAmount);
            }

            $acceptedStatus = $this->resolveBookingStatus('accepted');

            $booking = Booking::create([
                'booking_code' => $this->generateBookingCode(),
                'trip_id' => $trip->trip_id,
                'passenger_id' => $actor->user_id,
                'booking_type' => $bookingType,
                'seats_reserved' => $seatsReserved,
                'payment_method' => $data['payment_method'],
                'total_amount' => $totalAmount,
                'status_id' => $acceptedStatus->status_id,
                'confirmed_at' => now(),
            ]);

            $booking->pickupPoint()->create($pickupPointPayload);

            $payment = Payment::create([
                'booking_id' => $booking->booking_id,
                'wallet_id' => $wallet?->wallet_id,
                'payment_method' => $data['payment_method'],
                'amount' => $totalAmount,
                'payment_status' => $isWalletPayment ? 'paid' : 'pending',
                'transaction_reference' => $isWalletPayment ? Str::uuid()->toString() : null,
                'failure_reason' => null,
                'paid_at' => $isWalletPayment ? now() : null,
            ]);

            if ($isWalletPayment) {
                $this->debitWallet($wallet, $booking, $payment, $actor, $totalAmount);
            }

            $this->applyTripModeAfterBooking($trip, $bookingType, $seatsReserved);

            $booking->load(['trip.driver.user', 'pickupPoint', 'payments', 'passenger']);

            $this->notifyPassengerBookingConfirmed($booking, $actor, $totalAmount);

            if ($isWalletPayment) {
                $this->notifyPassengerWalletDebit($booking, $actor, $totalAmount);
            }

            event(new BookingCreated($booking));

            return $booking;
        });
    }

    private function ensureTripIsBookable(Trip $trip): void
    {
        $statusKey = $trip->status?->status_key;

        if (in_array($statusKey, [TripStatus::CANCELED, TripStatus::COMPLETED], true)) {
            throw ValidationException::withMessages([
                'trip_id' => 'لا يمكن الحجز على رحلة ملغاة أو منجزة.',
            ]);
        }

        if (! in_array($statusKey, [TripStatus::PENDING, TripStatus::ACTIVE], true)) {
            throw ValidationException::withMessages([
                'trip_id' => 'هذه الرحلة غير متاحة للحجز حالياً.',
            ]);
        }

        if ($trip->is_private_booked) {
            throw ValidationException::withMessages([
                'trip_id' => 'هذه الرحلة محجوزة كرحلة خاصة بالفعل.',
            ]);
        }
    }

    private function ensureDailyBookingLimit(User $actor): void
    {
        $count = Booking::query()
            ->where('passenger_id', $actor->user_id)
            ->whereDate('created_at', Carbon::today())
            ->whereHas('status', function ($query) {
                $query->whereNotIn('status_key', ['canceled', 'rejected']);
            })
            ->count();

        if ($count >= self::MAX_BOOKINGS_PER_DAY) {
            throw ValidationException::withMessages([
                'booking_limit' => 'لا يمكن حجز أكثر من ست رحلات فعالة في اليوم الواحد.',
            ]);
        }
    }

    private function normalizeSeatsReserved(string $bookingType, mixed $seatsReserved, Trip $trip): int
    {
        if ($bookingType === 'private') {
            return (int) $trip->total_seats;
        }

        $normalized = $seatsReserved !== null ? (int) $seatsReserved : 0;

        if ($normalized < 1) {
            throw ValidationException::withMessages([
                'seats_reserved' => 'يجب تحديد عدد المقاعد للحجز المشترك.',
            ]);
        }

        return $normalized;
    }

    private function ensureTripModeCompatibility(Trip $trip, string $requestedBookingType): void
    {
        $hasSharedBookings = $trip->bookings
            ->contains(fn (Booking $booking) => $booking->booking_type === 'shared' && ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true));
        $hasPrivateBookings = $trip->bookings
            ->contains(fn (Booking $booking) => $booking->booking_type === 'private' && ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true));

        if ($requestedBookingType === 'private') {
            if (! $trip->allow_private || $hasSharedBookings) {
                throw ValidationException::withMessages([
                    'booking_type' => 'هذه الرحلة أصبحت مشتركة فقط ولا يمكن حجزها كخاصة.',
                ]);
            }

            return;
        }

        if (! $trip->allow_shared || $hasPrivateBookings) {
            throw ValidationException::withMessages([
                'booking_type' => 'هذه الرحلة أصبحت خاصة فقط ولا يمكن الحجز عليها كمشتركة.',
            ]);
        }
    }

    private function buildPickupPointPayload(Trip $trip, array $pickupPoint): array
    {
        if (! empty($pickupPoint['trip_point_id'])) {
            $tripPoint = $trip->points->firstWhere('point_id', (int) $pickupPoint['trip_point_id']);

            if (! $tripPoint) {
                throw ValidationException::withMessages([
                    'pickup_point.trip_point_id' => 'نقطة التوقف المحددة غير مرتبطة بهذه الرحلة.',
                ]);
            }

            return [
                'trip_point_id' => $tripPoint->point_id,
                'governorate_id' => $this->resolveGovernorateIdForTripPoint($trip, $tripPoint),
                'point_name' => $this->resolveTripPointName($tripPoint),
                'address' => $tripPoint->address,
                'latitude' => $tripPoint->latitude,
                'longitude' => $tripPoint->longitude,
                'meeting_time' => optional($tripPoint->expected_arrival_time)->toDateTimeString(),
                'is_new' => false,
            ];
        }

        $latitude = (float) $pickupPoint['latitude'];
        $longitude = (float) $pickupPoint['longitude'];
        $this->ensurePointIsOnTripPath($trip, $latitude, $longitude);
        $resolvedPoint = $this->governorateResolverService->enrichPointsWithAddresses([[
            'point_type' => $pickupPoint['point_type'] ?? 'new point',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $pickupPoint['address'] ?? null,
            'sequence_order' => 1,
        ]])[0];
        $resolvedAddress = $resolvedPoint['address'] ?? null;
        $pointLabel = $pickupPoint['note']
            ?? $pickupPoint['point_name']
            ?? 'نقطة توقف جديدة';

        return [
            'trip_point_id' => null,
            'governorate_id' => $this->safeResolveGovernorateId([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $resolvedAddress,
                'sequence_order' => 0,
            ]),
            'point_name' => $pointLabel,
            'address' => $resolvedAddress,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'meeting_time' => $pickupPoint['meeting_time'] ?? null,
            'is_new' => true,
        ];
    }

    private function resolveTripPointName($tripPoint): string
    {
        return match ($tripPoint->point_type) {
            'start' => 'نقطة الانطلاق',
            'end' => 'نقطة الوصول',
            default => $tripPoint->note ?: 'نقطة توقف',
        };
    }

    private function resolveGovernorateIdForTripPoint(Trip $trip, $tripPoint): ?int
    {
        if ($tripPoint->point_type === 'start') {
            return $trip->start_governorate_id;
        }

        if ($tripPoint->point_type === 'end') {
            return $trip->end_governorate_id;
        }

        return $this->safeResolveGovernorateId([
            'latitude' => (float) $tripPoint->latitude,
            'longitude' => (float) $tripPoint->longitude,
            'address' => $tripPoint->address,
            'sequence_order' => (int) $tripPoint->sequence_order,
        ]);
    }

    private function safeResolveGovernorateId(array $point): ?int
    {
        try {
            return $this->governorateResolverService->resolveGovernorateIdFromPoint($point);
        } catch (Throwable) {
            return null;
        }
    }

    private function ensurePointIsOnTripPath(Trip $trip, float $latitude, float $longitude): void
    {
        $pathPoints = $this->resolvePathPoints($trip);

        if (count($pathPoints) < 2) {
            throw ValidationException::withMessages([
                'pickup_point' => 'لا يمكن التحقق من نقطة التوقف لأن مسار الرحلة غير مكتمل.',
            ]);
        }

        $minimumDistance = INF;

        for ($i = 0; $i < count($pathPoints) - 1; $i++) {
            $distance = $this->distanceFromPointToSegmentMeters(
                $latitude,
                $longitude,
                $pathPoints[$i]['latitude'],
                $pathPoints[$i]['longitude'],
                $pathPoints[$i + 1]['latitude'],
                $pathPoints[$i + 1]['longitude'],
            );

            $minimumDistance = min($minimumDistance, $distance);
        }

        if ($minimumDistance > self::MAX_PICKUP_DISTANCE_METERS) {
            throw ValidationException::withMessages([
                'pickup_point' => 'نقطة التوقف الجديدة يجب أن تكون على مسار الرحلة أو قريبة منه ضمن المسافة المسموح بها.',
            ]);
        }
    }

    private function resolvePathPoints(Trip $trip): array
    {
        if ($trip->route_polyline) {
            $decoded = $this->decodePolyline($trip->route_polyline);

            if (count($decoded) >= 2) {
                return $decoded;
            }
        }

        return $trip->points
            ->sortBy('sequence_order')
            ->map(fn ($point) => [
                'latitude' => (float) $point->latitude,
                'longitude' => (float) $point->longitude,
            ])
            ->values()
            ->all();
    }

    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $latitude = 0;
        $longitude = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            $latitude += $this->decodePolylineValue($encoded, $index);
            $longitude += $this->decodePolylineValue($encoded, $index);

            $points[] = [
                'latitude' => $latitude / 1e5,
                'longitude' => $longitude / 1e5,
            ];
        }

        return $points;
    }

    private function decodePolylineValue(string $encoded, int &$index): int
    {
        $shift = 0;
        $result = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }

    private function distanceFromPointToSegmentMeters(
        float $latitude,
        float $longitude,
        float $startLatitude,
        float $startLongitude,
        float $endLatitude,
        float $endLongitude
    ): float {
        $earthRadius = 6371000.0;
        $avgLatRad = deg2rad(($startLatitude + $endLatitude + $latitude) / 3);

        $toXY = static function (float $lat, float $lng) use ($earthRadius, $avgLatRad): array {
            return [
                'x' => deg2rad($lng) * $earthRadius * cos($avgLatRad),
                'y' => deg2rad($lat) * $earthRadius,
            ];
        };

        $p = $toXY($latitude, $longitude);
        $a = $toXY($startLatitude, $startLongitude);
        $b = $toXY($endLatitude, $endLongitude);

        $abx = $b['x'] - $a['x'];
        $aby = $b['y'] - $a['y'];
        $abLengthSquared = ($abx * $abx) + ($aby * $aby);

        if ($abLengthSquared === 0.0) {
            return sqrt((($p['x'] - $a['x']) ** 2) + (($p['y'] - $a['y']) ** 2));
        }

        $apx = $p['x'] - $a['x'];
        $apy = $p['y'] - $a['y'];
        $t = max(0.0, min(1.0, (($apx * $abx) + ($apy * $aby)) / $abLengthSquared));

        $closestX = $a['x'] + ($t * $abx);
        $closestY = $a['y'] + ($t * $aby);

        return sqrt((($p['x'] - $closestX) ** 2) + (($p['y'] - $closestY) ** 2));
    }

    private function calculateTotalAmount(Trip $trip, string $bookingType, int $seatsReserved): float
    {
        if ($bookingType === 'private') {
            return (float) ($trip->private_price ?? $trip->system_calculated_price);
        }

        return round((float) ($trip->shared_price ?? $trip->system_calculated_price) * $seatsReserved, 2);
    }

    private function ensureWalletCanPay(?Wallet $wallet, float $totalAmount): void
    {
        if (! $wallet) {
            throw ValidationException::withMessages([
                'payment_method' => 'المحفظة الإلكترونية غير متوفرة لهذا المستخدم.',
            ]);
        }

        if ((float) $wallet->balance < $totalAmount) {
            throw ValidationException::withMessages([
                'payment_method' => 'الرصيد غير كافي للحجز يرجى شحن المحفظة.',
            ]);
        }
    }

    private function debitWallet(Wallet $wallet, Booking $booking, Payment $payment, User $actor, float $totalAmount): void
    {
        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance - $totalAmount, 2);

        $wallet->update(['balance' => $afterBalance]);

        WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $totalAmount,
            'transaction_type' => 'debit',
            'status' => 'completed',
            'transaction_reference' => $payment->transaction_reference,
            'description' => 'خصم قيمة الحجز من المحفظة الإلكترونية.',
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $actor->user_id,
        ]);
    }

    private function applyTripModeAfterBooking(Trip $trip, string $bookingType, int $seatsReserved): void
    {
        if ($bookingType === 'private') {
            $trip->update([
                'available_seats' => 0,
                'is_private_booked' => true,
                'allow_shared' => false,
                'allow_private' => true,
            ]);

            return;
        }

        $trip->update([
            'available_seats' => max(0, (int) $trip->available_seats - $seatsReserved),
            'allow_shared' => true,
            'allow_private' => false,
        ]);
    }

    private function notifyPassengerBookingConfirmed(Booking $booking, User $actor, float $totalAmount): void
    {
        $notification = Notification::create([
            'title' => 'تم تأكيد الحجز',
            'body' => "تم تأكيد الحجز رقم {$booking->booking_code} بقيمة {$totalAmount}.",
            'notification_type' => 'booking_confirmed_passenger',
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        UserNotification::firstOrCreate(
            [
                'notification_id' => $notification->notification_id,
                'user_id' => $actor->user_id,
            ],
            [
                'is_read' => false,
                'is_sent' => true,
                'sent_at' => now(),
            ]
        );
    }

    private function notifyPassengerWalletDebit(Booking $booking, User $actor, float $totalAmount): void
    {
        $notification = Notification::create([
            'title' => 'تم خصم قيمة الرحلة',
            'body' => "تم خصم مبلغ {$totalAmount} من محفظتك للحجز رقم {$booking->booking_code}.",
            'notification_type' => 'wallet_booking_debit',
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        UserNotification::firstOrCreate(
            [
                'notification_id' => $notification->notification_id,
                'user_id' => $actor->user_id,
            ],
            [
                'is_read' => false,
                'is_sent' => true,
                'sent_at' => now(),
            ]
        );
    }

    private function resolveBookingStatus(string $statusKey): BookingStatus
    {
        $status = BookingStatus::query()
            ->where('status_key', $statusKey)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw new RuntimeException('حالة الحجز المطلوبة غير موجودة.');
        }

        return $status;
    }

    private function generateBookingCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }
}
