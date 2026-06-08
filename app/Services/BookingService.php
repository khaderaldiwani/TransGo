<?php

namespace App\Services;

use App\Events\BookingCreated;
use App\Models\AccountRestriction;
use App\Models\Booking;
use App\Models\BookingCancellation;
use App\Models\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BookingService
{
    private const MAX_BOOKINGS_PER_DAY = 6;
    private const MAX_PICKUP_DISTANCE_METERS = 300.0;
    private const FREE_CANCELLATION_GRACE_MINUTES = 30;
    private const EARLY_CANCELLATION_THRESHOLD_HOURS = 12.0;
    private const MID_CANCELLATION_THRESHOLD_HOURS = 6.0;
    private const LATE_CANCELLATION_THRESHOLD_HOURS = 2.0;
    private const EARLY_CANCELLATION_MONTHLY_LIMIT = 6;
    private const EARLY_CANCELLATION_RATING_THRESHOLD = 3;
    private const TEMP_BAN_DAYS = 3;

    public function __construct(
        private readonly GovernorateResolverService $governorateResolverService,
        private readonly ReceiptService $receiptService,
        private readonly TripClusterService $tripClusterService,
        private readonly NotificationDispatchService $notifications
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
            $this->ensurePassengerCanBook($actor, (string) $data['payment_method']);
            $this->ensureDailyBookingLimit($actor);

            $bookingType = (string) $data['booking_type'];
            $seatsReserved = $this->normalizeSeatsReserved($bookingType, $data['seats_reserved'] ?? null, $trip);
            $this->ensureTripModeCompatibility($trip, $bookingType);
            $this->ensureSharedTripIsVisibleForBooking($trip, $bookingType);

            if ($bookingType === 'shared' && $seatsReserved > $trip->available_seats) {
                throw ValidationException::withMessages([
                    'seats_reserved' => 'عدد المقاعد المطلوبة أكبر من المقاعد المتاحة.',
                ]);
            }

            $pickupPointPayload = $this->buildPickupPointPayload($trip, $data['pickup_point']);
            $totalAmount = $this->calculateTotalAmount($trip, $bookingType, $seatsReserved);
            $isWalletPayment = $data['payment_method'] === 'electronic';
            $wallet = $isWalletPayment
                ? $this->resolveLockedWalletForUser($actor->user_id)
                : null;
            $driverWallet = $isWalletPayment
                ? $this->resolveLockedWalletForUser((int) $trip->driver_id)
                : null;
            $driverUser = $isWalletPayment
                ? $this->resolveTripDriverUser($trip)
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
                $this->debitWallet($wallet, $booking, $payment, $actor, $driverUser, $totalAmount);
                $this->creditDriverWallet($driverWallet, $booking, $payment, $driverUser, $actor, $totalAmount);
            }

            $this->applyTripModeAfterBooking($trip, $bookingType, $seatsReserved);
            $this->tripClusterService->refreshClusterAvailability($trip->fresh()->cluster_id);

            $booking->load(['trip.driver.user', 'pickupPoint', 'payments', 'passenger']);

            $this->notifyPassengerBookingConfirmed($booking, $actor, $totalAmount);

            if ($isWalletPayment) {
                $this->notifyPassengerWalletDebit($booking, $actor, $totalAmount);
            }

            event(new BookingCreated($booking));

            return $booking;
        });
    }

    public function cancelBooking(int $bookingId, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($bookingId, $actor, $reason) {
            $booking = Booking::query()
                ->with(['trip.status', 'trip.driver.user', 'status', 'pickupPoint', 'passenger', 'payments'])
                ->lockForUpdate()
                ->find($bookingId);

            if (! $booking || (int) $booking->passenger_id !== (int) $actor->user_id) {
                throw ValidationException::withMessages([
                    'booking_id' => 'الحجز المطلوب غير موجود.',
                ]);
            }

            $trip = Trip::query()
                ->with(['status', 'bookings.status'])
                ->lockForUpdate()
                ->find($booking->trip_id);

            if (! $trip) {
                throw ValidationException::withMessages([
                    'trip_id' => 'الرحلة المرتبطة بالحجز غير موجودة.',
                ]);
            }

            $this->ensureBookingIsCancelable($booking, $trip);

            $canceledStatus = $this->resolveBookingStatus('canceled');
            $currentStatusId = $booking->status_id;
            $penalty = $this->resolveCancellationPenalty($booking, $trip);
            $payment = Payment::query()
                ->where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->first();

            $wallet = $booking->payment_method === 'electronic'
                ? $this->resolveLockedWalletForUser($actor->user_id)
                : null;
            $driverWallet = $booking->payment_method === 'electronic'
                ? $this->resolveLockedWalletForUser((int) $trip->driver_id)
                : null;
            $driverUser = $booking->payment_method === 'electronic'
                ? $this->resolveTripDriverUser($trip)
                : null;

            if ($penalty['wallet_refund_amount'] > 0) {
                $this->refundWallet(
                    $wallet,
                    $booking,
                    $payment,
                    $actor,
                    $driverUser,
                    (float) $penalty['wallet_refund_amount']
                );

                $this->debitDriverWalletForRefund(
                    $driverWallet,
                    $booking,
                    $payment,
                    $driverUser,
                    $actor,
                    (float) $penalty['wallet_refund_amount']
                );
            }

            $booking->update([
                'status_id' => $canceledStatus->status_id,
                'canceled_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->booking_id,
                'from_status_id' => $currentStatusId,
                'to_status_id' => $canceledStatus->status_id,
                'changed_by' => $actor->user_id,
                'reason' => $reason,
                'changed_at' => now(),
            ]);

            BookingCancellation::updateOrCreate(
                ['booking_id' => $booking->booking_id],
                [
                    'canceled_by' => $actor->user_id,
                    'reason' => $reason,
                    'cancellation_time' => now(),
                    'hours_before_departure' => $penalty['hours_before_departure'],
                    'penalty_percentage' => $penalty['penalty_percentage'],
                    'penalty_amount' => $penalty['penalty_amount'],
                    'wallet_refund_amount' => $penalty['wallet_refund_amount'],
                    'rating_penalty' => $penalty['rating_penalty'],
                ]
            );

            if ($payment) {
                $this->syncPaymentAfterCancellation($payment, $penalty);
            }

            $this->restoreTripCapacityAfterCancellation($trip, $booking);
            $this->tripClusterService->refreshClusterAvailability($trip->fresh()->cluster_id);
            $restriction = $this->applyRestrictionsAfterCancellation($actor, $penalty, $booking);

            if ($penalty['rating_penalty'] > 0) {
                $this->applyPassengerRatingPenalty(
                    $actor,
                    $booking,
                    (float) $penalty['rating_penalty'],
                    (string) $penalty['rating_reason']
                );
            }

            $booking->load(['trip.status', 'pickupPoint', 'status', 'payments']);

            $this->notifyPassengerBookingCanceled($booking, $actor, $penalty, $restriction);
            $this->notifyDriverBookingCanceled($booking, $actor);

            return [
                'booking_id' => $booking->booking_id,
                'booking_code' => $booking->booking_code,
                'status' => [
                    'id' => $canceledStatus->status_id,
                    'key' => $canceledStatus->status_key,
                    'name' => $canceledStatus->status_name,
                ],
                'penalty' => [
                    'grace_period_applied' => $penalty['grace_period_applied'],
                    'hours_before_departure' => $penalty['hours_before_departure'],
                    'percentage' => $penalty['penalty_percentage'],
                    'amount' => $penalty['penalty_amount'],
                    'wallet_refund_amount' => $penalty['wallet_refund_amount'],
                    'rating_penalty' => $penalty['rating_penalty'],
                ],
                'restriction' => $restriction,
                'trip' => [
                    'trip_id' => $trip->trip_id,
                    'available_seats' => (int) $trip->fresh()->available_seats,
                ],
            ];
        });
    }

    private function ensureTripIsBookable(Trip $trip): void
    {
        $statusKey = $trip->status?->status_key;

        if (in_array($statusKey, [TripStatus::CANCELED, TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED], true)) {
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

    private function ensurePassengerCanBook(User $actor, string $paymentMethod): void
    {
        $restrictions = $this->resolveActiveRestrictions($actor);

        if ($restrictions->firstWhere('restriction_type', 'temporary_ban')) {
            throw ValidationException::withMessages([
                'booking_restriction' => 'الحساب مقيد مؤقتاً ولا يمكن تنفيذ حجوزات جديدة حالياً.',
            ]);
        }

        if ($paymentMethod === 'cash' && $restrictions->firstWhere('restriction_type', 'cash_block')) {
            throw ValidationException::withMessages([
                'payment_method' => 'هذا الحساب يمكنه الحجز حالياً عبر الدفع الإلكتروني فقط.',
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

    private function ensureSharedTripIsVisibleForBooking(Trip $trip, string $requestedBookingType): void
    {
        if ($requestedBookingType !== 'shared' || ! $trip->cluster_id) {
            return;
        }

        if (! (bool) $trip->is_booking_visible) {
            throw ValidationException::withMessages([
                'trip_id' => 'هذه الرحلة غير متاحة للحجز المشترك حالياً. يرجى اختيار رحلة مفتوحة من نتائج البحث.',
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
                'payment_method' => 'الرصيد غير كاف للحجز يرجى شحن المحفظة.',
            ]);
        }
    }

    private function debitWallet(
        Wallet $wallet,
        Booking $booking,
        Payment $payment,
        User $actor,
        User $driver,
        float $totalAmount
    ): void
    {
        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance - $totalAmount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
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

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $actor->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => 'booking_payment',
            'direction' => 'debit',
            'status' => 'paid',
            'amount' => $totalAmount,
            'counterparty_user_id' => $driver->user_id,
            'counterparty_name' => $driver->full_name,
            'reason' => 'خصم قيمة الحجز الإلكتروني من محفظة الراكب.',
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function creditDriverWallet(
        ?Wallet $wallet,
        Booking $booking,
        Payment $payment,
        User $driver,
        User $passenger,
        float $amount
    ): void
    {
        if (! $wallet) {
            throw new RuntimeException('تعذر إضافة المبلغ إلى محفظة السائق.');
        }

        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance + $amount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $amount,
            'transaction_type' => 'credit',
            'status' => 'completed',
            'transaction_reference' => $payment->transaction_reference,
            'description' => 'إضافة قيمة الحجز الإلكتروني إلى محفظة السائق.',
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $passenger->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $driver->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => 'booking_income',
            'direction' => 'credit',
            'status' => 'received',
            'amount' => $amount,
            'counterparty_user_id' => $passenger->user_id,
            'counterparty_name' => $passenger->full_name,
            'reason' => 'إيراد حجز إلكتروني جديد.',
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function refundWallet(
        ?Wallet $wallet,
        Booking $booking,
        ?Payment $payment,
        User $actor,
        ?User $driver,
        float $refundAmount
    ): void
    {
        if (! $wallet) {
            throw new RuntimeException('تعذر تنفيذ الاسترداد لأن المحفظة غير متوفرة.');
        }

        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance + $refundAmount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $refundAmount,
            'transaction_type' => 'refund',
            'status' => 'completed',
            'transaction_reference' => $payment?->transaction_reference ?? Str::uuid()->toString(),
            'description' => 'استرداد مبلغ من الحجز الملغى إلى المحفظة الإلكترونية.',
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $actor->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $actor->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment?->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => 'booking_refund',
            'direction' => 'credit',
            'status' => 'received',
            'amount' => $refundAmount,
            'counterparty_user_id' => $driver?->user_id,
            'counterparty_name' => $driver?->full_name,
            'reason' => 'استرداد إلى محفظة الراكب بعد إلغاء الحجز.',
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function debitDriverWalletForRefund(
        ?Wallet $wallet,
        Booking $booking,
        ?Payment $payment,
        ?User $driver,
        User $passenger,
        float $refundAmount
    ): void
    {
        if (! $wallet || ! $driver) {
            return;
        }

        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance - $refundAmount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $refundAmount,
            'transaction_type' => 'adjustment',
            'status' => 'completed',
            'transaction_reference' => $payment?->transaction_reference ?? Str::uuid()->toString(),
            'description' => 'خصم من محفظة السائق مقابل استرداد حجز ملغى للراكب.',
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $passenger->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $driver->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment?->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => 'booking_refund_reversal',
            'direction' => 'debit',
            'status' => 'paid',
            'amount' => $refundAmount,
            'counterparty_user_id' => $passenger->user_id,
            'counterparty_name' => $passenger->full_name,
            'reason' => 'خصم من رصيد السائق لإعادة المبلغ إلى الراكب بعد الإلغاء.',
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
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

    private function ensureBookingIsCancelable(Booking $booking, Trip $trip): void
    {
        $statusKey = $booking->status?->status_key;

        if (in_array($statusKey, ['canceled', 'rejected', 'completed'], true)) {
            throw ValidationException::withMessages([
                'booking_id' => 'لا يمكن إلغاء هذا الحجز بحالته الحالية.',
            ]);
        }

        if (in_array($trip->status?->status_key, [TripStatus::CANCELED, TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'trip_id' => 'لا يمكن إلغاء الحجز لأن الرحلة أصبحت منتهية أو ملغاة.',
            ]);
        }
    }

    private function resolveCancellationPenalty(Booking $booking, Trip $trip): array
    {
        $departureTime = Carbon::parse($trip->departure_time);
        $hoursBeforeDeparture = round(max(0, ($departureTime->getTimestamp() - now()->getTimestamp()) / 3600), 2);
        $minutesSinceBooking = $booking->created_at
            ? (int) $booking->created_at->diffInMinutes(now())
            : self::FREE_CANCELLATION_GRACE_MINUTES;

        if ($minutesSinceBooking < self::FREE_CANCELLATION_GRACE_MINUTES) {
            return $this->buildGraceCancellationPenalty($booking, $hoursBeforeDeparture);
        }

        $monthlyEarlyCancellations = $this->countMonthlyEarlyCancellations((int) $booking->passenger_id) + 1;
        $ratingPenalty = 0.0;
        $ratingReason = null;
        $restrictionType = null;
        $restrictionReason = null;
        $penaltyPercentage = 0.0;

        if ($hoursBeforeDeparture >= self::EARLY_CANCELLATION_THRESHOLD_HOURS) {
            if ($monthlyEarlyCancellations > self::EARLY_CANCELLATION_RATING_THRESHOLD) {
                $ratingPenalty = 0.10;
                $ratingReason = 'خصم تقييم بسبب تكرار إلغاء الحجز المبكر.';
            }

            if ($monthlyEarlyCancellations > self::EARLY_CANCELLATION_MONTHLY_LIMIT) {
                $restrictionType = 'temporary_ban';
                $restrictionReason = 'تكرار إلغاء الحجوزات قبل 12 ساعة أو أكثر أكثر من 6 مرات خلال الشهر.';
            }
        } elseif ($hoursBeforeDeparture < self::MID_CANCELLATION_THRESHOLD_HOURS) {
            $ratingPenalty = 0.30;
            $ratingReason = 'خصم تقييم بسبب إلغاء الحجز قبل أقل من 6 ساعات من الانطلاق.';

            if ($booking->payment_method === 'electronic') {
                $penaltyPercentage = $hoursBeforeDeparture < self::LATE_CANCELLATION_THRESHOLD_HOURS ? 100.0 : 50.0;
            } else {
                $restrictionType = 'cash_block';
                $restrictionReason = 'تم تقييد الدفع النقدي بسبب إلغاء حجز نقدي قبل أقل من 12 ساعة من الانطلاق.';
            }
        } else {
            $ratingPenalty = 0.20;
            $ratingReason = 'خصم تقييم بسبب إلغاء الحجز قبل أقل من 12 ساعة من الانطلاق.';

            if ($booking->payment_method === 'electronic') {
                $penaltyPercentage = 25.0;
            } else {
                $restrictionType = 'cash_block';
                $restrictionReason = 'تم تقييد الدفع النقدي بسبب إلغاء حجز نقدي قبل أقل من 12 ساعة من الانطلاق.';
            }
        }

        $totalAmount = (float) $booking->total_amount;
        $penaltyAmount = round(($totalAmount * $penaltyPercentage) / 100, 2);
        $refundAmount = $booking->payment_method === 'electronic'
            ? round(max(0, $totalAmount - $penaltyAmount), 2)
            : 0.0;

        return [
            'grace_period_applied' => false,
            'hours_before_departure' => $hoursBeforeDeparture,
            'penalty_percentage' => $penaltyPercentage,
            'penalty_amount' => $penaltyAmount,
            'wallet_refund_amount' => $refundAmount,
            'rating_penalty' => $ratingPenalty,
            'rating_reason' => $ratingReason,
            'restriction_type' => $restrictionType,
            'restriction_reason' => $restrictionReason,
        ];
    }

    private function buildGraceCancellationPenalty(Booking $booking, float $hoursBeforeDeparture): array
    {
        return [
            'grace_period_applied' => true,
            'hours_before_departure' => $hoursBeforeDeparture,
            'penalty_percentage' => 0.0,
            'penalty_amount' => 0.0,
            'wallet_refund_amount' => $booking->payment_method === 'electronic'
                ? round((float) $booking->total_amount, 2)
                : 0.0,
            'rating_penalty' => 0.0,
            'rating_reason' => null,
            'restriction_type' => null,
            'restriction_reason' => null,
        ];
    }

    private function countMonthlyEarlyCancellations(int $userId): int
    {
        return BookingCancellation::query()
            ->where('canceled_by', $userId)
            ->whereYear('cancellation_time', now()->year)
            ->whereMonth('cancellation_time', now()->month)
            ->where('hours_before_departure', '>=', self::EARLY_CANCELLATION_THRESHOLD_HOURS)
            ->count();
    }

    private function restoreTripCapacityAfterCancellation(Trip $trip, Booking $booking): void
    {
        $remainingActiveBookings = Booking::query()
            ->with('status')
            ->where('trip_id', $trip->trip_id)
            ->where('booking_id', '!=', $booking->booking_id)
            ->get()
            ->filter(fn (Booking $tripBooking) => ! in_array($tripBooking->status?->status_key, ['canceled', 'rejected'], true));

        $seatsToRestore = (int) $booking->seats_reserved;
        $availableSeats = min((int) $trip->total_seats, (int) $trip->available_seats + $seatsToRestore);

        $hasSharedBookings = $remainingActiveBookings->contains(fn (Booking $tripBooking) => $tripBooking->booking_type === 'shared');
        $hasPrivateBookings = $remainingActiveBookings->contains(fn (Booking $tripBooking) => $tripBooking->booking_type === 'private');

        if ($hasPrivateBookings) {
            $trip->update([
                'available_seats' => 0,
                'is_private_booked' => true,
                'allow_shared' => false,
                'allow_private' => true,
            ]);

            return;
        }

        if ($hasSharedBookings) {
            $trip->update([
                'available_seats' => $availableSeats,
                'is_private_booked' => false,
                'allow_shared' => true,
                'allow_private' => false,
            ]);

            return;
        }

        $trip->update([
            'available_seats' => (int) $trip->total_seats,
            'is_private_booked' => false,
            'allow_shared' => $trip->shared_price !== null,
            'allow_private' => $trip->private_price !== null,
        ]);
    }

    private function applyRestrictionsAfterCancellation(User $actor, array $penalty, Booking $booking): ?array
    {
        if (! $penalty['restriction_type']) {
            return null;
        }

        $restriction = match ($penalty['restriction_type']) {
            'temporary_ban' => $this->createOrRefreshTemporaryBan($actor, (string) $penalty['restriction_reason']),
            'cash_block' => $this->createCashOnlyRestriction($actor, (string) $penalty['restriction_reason']),
            default => null,
        };

        if (! $restriction) {
            return null;
        }

        $this->notifyPassengerRestrictionApplied($booking, $actor, $restriction);

        return [
            'type' => $restriction->restriction_type,
            'reason' => $restriction->reason,
            'start_date' => optional($restriction->start_date)->toIso8601String(),
            'end_date' => optional($restriction->end_date)->toIso8601String(),
        ];
    }

    private function createOrRefreshTemporaryBan(User $actor, string $reason): AccountRestriction
    {
        $activeBan = AccountRestriction::query()
            ->where('user_id', $actor->user_id)
            ->where('restriction_type', 'temporary_ban')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->first();

        $endDate = now()->addDays(self::TEMP_BAN_DAYS);

        if ($activeBan) {
            $activeBan->update([
                'start_date' => now(),
                'end_date' => $endDate,
                'reason' => $reason,
                'is_active' => true,
            ]);

            return $activeBan->fresh();
        }

        return AccountRestriction::create([
            'user_id' => $actor->user_id,
            'restriction_type' => 'temporary_ban',
            'start_date' => now(),
            'end_date' => $endDate,
            'reason' => $reason,
            'is_active' => true,
        ]);
    }

    private function createCashOnlyRestriction(User $actor, string $reason): AccountRestriction
    {
        $activeRestriction = AccountRestriction::query()
            ->where('user_id', $actor->user_id)
            ->where('restriction_type', 'cash_block')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->first();

        if ($activeRestriction) {
            return $activeRestriction;
        }

        return AccountRestriction::create([
            'user_id' => $actor->user_id,
            'restriction_type' => 'cash_block',
            'start_date' => now(),
            'end_date' => null,
            'reason' => $reason,
            'is_active' => true,
        ]);
    }

    private function applyPassengerRatingPenalty(User $actor, Booking $booking, float $penaltyAmount, string $reason): void
    {
        $currentRating = (float) ($actor->rating ?? User::DEFAULT_RATING);
        $newRating = max(User::MIN_RATING, round($currentRating - $penaltyAmount, 2));

        $actor->update([
            'rating' => $newRating,
            'rating_last_updated' => now(),
        ]);

        DB::table('passenger_rating_logs')->insert([
            'user_id' => $actor->user_id,
            'booking_id' => $booking->booking_id,
            'rating_change' => -1 * abs($penaltyAmount),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function syncPaymentAfterCancellation(Payment $payment, array $penalty): void
    {
        if ($payment->payment_method !== 'electronic') {
            $payment->update([
                'payment_status' => 'canceled',
                'failure_reason' => 'تم إلغاء الحجز قبل الدفع النقدي.',
            ]);

            return;
        }

        $refundAmount = (float) $penalty['wallet_refund_amount'];
        $totalAmount = (float) $payment->amount;

        if ($refundAmount >= $totalAmount) {
            $payment->update([
                'payment_status' => 'refunded',
                'failure_reason' => 'تم رد كامل المبلغ بعد إلغاء الحجز.',
            ]);

            return;
        }

        if ($refundAmount > 0) {
            $payment->update([
                'payment_status' => 'partially_refunded',
                'failure_reason' => 'تم رد جزء من المبلغ بعد تطبيق غرامة الإلغاء.',
            ]);

            return;
        }

        $payment->update([
            'payment_status' => 'paid',
            'failure_reason' => 'تم إلغاء الحجز بدون استرداد بسبب غرامة الإلغاء الكاملة.',
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

        $this->sendNotificationToUser($notification, $actor->user_id);
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

        $this->sendNotificationToUser($notification, $actor->user_id);
    }

    private function notifyPassengerBookingCanceled(
        Booking $booking,
        User $actor,
        array $penalty,
        ?array $restriction
    ): void {
        $body = $penalty['grace_period_applied']
            ? "تم إلغاء الحجز رقم {$booking->booking_code} ضمن مهلة السماح بدون أي عقوبة."
            : "تم إلغاء الحجز رقم {$booking->booking_code}. قيمة الغرامة {$penalty['penalty_amount']} وقيمة الاسترداد {$penalty['wallet_refund_amount']}.";

        if ($restriction) {
            $body .= " تم تطبيق تقييد من النوع {$restriction['type']}.";
        }

        $notification = Notification::create([
            'title' => 'تم إلغاء الحجز',
            'body' => $body,
            'notification_type' => 'booking_canceled_passenger',
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        $this->sendNotificationToUser($notification, $actor->user_id);
    }

    private function notifyDriverBookingCanceled(Booking $booking, User $actor): void
    {
        $driverUserId = $booking->trip?->driver?->user?->user_id;

        if (! $driverUserId) {
            return;
        }

        $notification = Notification::create([
            'title' => 'إلغاء حجز راكب',
            'body' => "ألغى الراكب {$actor->full_name} الحجز رقم {$booking->booking_code}.",
            'notification_type' => 'booking_canceled_driver',
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_DRIVER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        $this->sendNotificationToUser($notification, $driverUserId);
    }

    private function notifyPassengerRestrictionApplied(Booking $booking, User $actor, AccountRestriction $restriction): void
    {
        $notification = Notification::create([
            'title' => 'تم تطبيق تقييد على الحساب',
            'body' => $restriction->reason ?? 'تم تطبيق تقييد جديد على حسابك.',
            'notification_type' => 'passenger_restriction_applied',
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        $this->sendNotificationToUser($notification, $actor->user_id);
    }

    private function sendNotificationToUser(Notification $notification, int $userId): void
    {
        $this->notifications->sendExistingToUser($notification, $userId);
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

    private function resolveActiveRestrictions(User $actor): Collection
    {
        AccountRestriction::query()
            ->where('user_id', $actor->user_id)
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now())
            ->update(['is_active' => false]);

        return AccountRestriction::query()
            ->where('user_id', $actor->user_id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->get();
    }

    private function resolveLockedWalletForUser(int $userId): Wallet
    {
        Wallet::firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0]
        );

        return Wallet::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function resolveTripDriverUser(Trip $trip): User
    {
        $driverUser = $trip->driver?->user;

        if ($driverUser instanceof User) {
            return $driverUser;
        }

        $driverUser = User::query()->find($trip->driver_id);

        if (! $driverUser) {
            throw new RuntimeException('تعذر العثور على السائق المرتبط بهذه الرحلة.');
        }

        return $driverUser;
    }

    private function generateBookingCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }
}
