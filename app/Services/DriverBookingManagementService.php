<?php

namespace App\Services;

use App\Models\AccountRestriction;
use App\Models\Booking;
use App\Models\BookingAttendance;
use App\Models\BookingAttendanceStatus;
use App\Models\BookingCancellation;
use App\Models\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\DriverProfile;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DriverBookingManagementService
{
    public function __construct(
        private readonly ReceiptService $receiptService
    ) {
    }

    public function listGroupedBookings(User $actor, ?string $status = null): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $bookings = $this->baseDriverBookingsQuery($driverProfile, $status)
            ->orderBy('trip.departure_time')
            ->orderByDesc('bookings.created_at')
            ->get();

        return $this->transformGroupedBookings($bookings, $actor);
    }

    public function listTripBookings(int $tripId, User $actor, ?string $status = null): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $trip = Trip::query()
            ->where('trip_id', $tripId)
            ->where('driver_id', $driverProfile->user_id)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        $bookings = $this->baseDriverBookingsQuery($driverProfile, $status)
            ->where('bookings.trip_id', $tripId)
            ->orderByDesc('bookings.created_at')
            ->get();

        $unreadIds = $this->resolveUnreadBookingIds($actor, $bookings->pluck('booking_id')->all());

        return [
            'trip_id' => $tripId,
            'items' => $bookings->map(fn (Booking $booking) => $this->transformBookingCard($booking, $unreadIds))->values(),
        ];
    }

    public function showBookingDetails(int $bookingId, User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $booking = $this->baseDriverBookingsQuery($driverProfile)
            ->where('bookings.booking_id', $bookingId)
            ->first();

        if (! $booking) {
            throw new RuntimeException('طلب الحجز المطلوب غير موجود أو لا يتبع لهذا السائق.', 404);
        }

        $this->markBookingRequestAsRead($actor, $booking);

        return $this->transformBookingDetails($booking->fresh([
            'trip.status',
            'trip.startGovernorate',
            'trip.endGovernorate',
            'passenger',
            'pickupPoint.governorate',
            'status',
            'attendanceStatus',
            'attendance',
            'payments',
            'statusLogs.toStatus',
            'cancellation',
        ]));
    }

    public function updateBookingStatus(int $bookingId, User $actor, string $statusKey, ?string $reason): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $booking = $this->baseDriverBookingsQuery($driverProfile)
            ->where('bookings.booking_id', $bookingId)
            ->lockForUpdate()
            ->first();

        if (! $booking) {
            throw new RuntimeException('طلب الحجز المطلوب غير موجود أو لا يتبع لهذا السائق.', 404);
        }

        $trip = Trip::query()
            ->with(['status', 'bookings.status'])
            ->lockForUpdate()
            ->find($booking->trip_id);

        if (! $trip) {
            throw new RuntimeException('الرحلة المرتبطة بالحجز غير موجودة.', 404);
        }

        $this->ensureBookingStatusCanBeChanged($booking);

        return DB::transaction(function () use ($booking, $trip, $actor, $statusKey, $reason) {
            $targetStatus = $this->resolveBookingStatus($statusKey);
            $fromStatusId = $booking->status_id;
            $payment = Payment::query()
                ->where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->first();

            if ($booking->status?->status_key === $statusKey) {
                return $this->transformBookingDetails($booking->fresh([
                    'trip.status',
                    'trip.startGovernorate',
                    'trip.endGovernorate',
                    'passenger',
                    'pickupPoint.governorate',
                    'status',
                    'attendanceStatus',
                    'attendance',
                    'payments',
                    'statusLogs.toStatus',
                    'cancellation',
                ]));
            }

            if ($statusKey === 'rejected') {
                $this->syncPaymentForRejection($booking, $payment, $actor, $reason);
                $this->restoreTripCapacity($trip, $booking);

                $booking->forceFill([
                    'status_id' => $targetStatus->status_id,
                    'rejected_at' => now(),
                ])->save();
            } else {
                $this->ensureTripCanAcceptBooking($trip, $booking);
                $this->syncPaymentForAcceptance($booking, $payment, $actor);
                $this->reserveTripCapacity($trip, $booking);

                $booking->forceFill([
                    'status_id' => $targetStatus->status_id,
                    'rejected_at' => null,
                ])->save();
            }

            BookingStatusLog::create([
                'booking_id' => $booking->booking_id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $targetStatus->status_id,
                'changed_by' => $actor->user_id,
                'reason' => $reason,
                'changed_at' => now(),
            ]);

            $this->notifyPassengerStatusChange($booking->fresh(['pickupPoint']), $actor, $statusKey, $reason);

            return $this->showBookingDetails($booking->booking_id, $actor);
        });
    }

    public function updateBookingAttendance(int $bookingId, User $actor, string $attendanceKey, ?string $notes): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $booking = $this->baseDriverBookingsQuery($driverProfile)
            ->where('bookings.booking_id', $bookingId)
            ->lockForUpdate()
            ->first();

        if (! $booking) {
            throw new RuntimeException('طلب الحجز المطلوب غير موجود أو لا يتبع لهذا السائق.', 404);
        }

        if (in_array($booking->status?->status_key, ['canceled', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'booking_id' => 'لا يمكن تسجيل الحضور أو الغياب لحجز مرفوض أو ملغى.',
            ]);
        }

        if ($booking->trip?->departure_time && now()->lt(Carbon::parse($booking->trip->departure_time))) {
            throw ValidationException::withMessages([
                'attendance_status' => 'يمكن تسجيل الحضور أو الغياب بعد بدء الرحلة فقط.',
            ]);
        }

        $currentAttendanceKey = $booking->attendanceStatus?->status_key;

        if ($currentAttendanceKey === $attendanceKey) {
            return $this->showBookingDetails($booking->booking_id, $actor);
        }

        if (in_array($currentAttendanceKey, ['present', 'absent'], true)) {
            throw ValidationException::withMessages([
                'attendance_status' => 'تم تسجيل حضور أو غياب هذا الراكب مسبقاً، ولا يمكن تعديله مرة أخرى.',
            ]);
        }

        return DB::transaction(function () use ($booking, $actor, $attendanceKey, $notes) {
            $attendanceStatus = $this->resolveAttendanceStatus($attendanceKey);
            $penaltyAmount = 0.0;
            $ratingPenalty = 0.0;

            if ($attendanceKey === 'absent') {
                [$penaltyAmount, $ratingPenalty] = $this->applyNoShowConsequences($booking, $actor, $notes);
            } else {
                $this->applyPassengerRatingBonus(
                    $booking->passenger,
                    $booking,
                    0.1,
                    'زيادة التقييم بسبب حضور الراكب إلى نقطة الالتقاء.'
                );
            }

            BookingAttendance::updateOrCreate(
                ['booking_id' => $booking->booking_id],
                [
                    'status_id' => $attendanceStatus->status_id,
                    'marked_by' => $actor->user_id,
                    'marked_at' => now(),
                    'penalty_amount' => $penaltyAmount,
                    'rating_penalty' => $ratingPenalty,
                    'notes' => $notes,
                ]
            );

            $booking->forceFill([
                'attendance_status_id' => $attendanceStatus->status_id,
            ])->save();

            $this->notifyPassengerAttendanceMarked($booking->fresh(['pickupPoint']), $actor, $attendanceStatus, $notes);

            return $this->showBookingDetails($booking->booking_id, $actor);
        });
    }

    private function resolveDriverProfile(User $actor): DriverProfile
    {
        $actor->loadMissing('driverProfile');

        if (! $actor->driverProfile) {
            throw new RuntimeException('المستخدم الحالي لا يملك ملف سائق.', 403);
        }

        return $actor->driverProfile;
    }

    private function syncPaymentForRejection(Booking $booking, ?Payment $payment, User $actor, ?string $reason): void
    {
        if (! $payment) {
            return;
        }

        if ($payment->payment_method !== 'electronic') {
            $payment->update([
                'payment_status' => 'canceled',
                'failure_reason' => $reason ?: 'تم رفض الحجز من قبل السائق.',
            ]);

            return;
        }

        if (in_array($payment->payment_status, ['refunded', 'canceled'], true)) {
            return;
        }

        $amount = round((float) $payment->amount, 2);
        $passenger = $booking->passenger ?? User::query()->find($booking->passenger_id);

        if (! $passenger) {
            throw new RuntimeException('تعذر العثور على الراكب المرتبط بالحجز.');
        }

        $passengerWallet = $this->resolveLockedWalletForUser($passenger->user_id);
        $driverWallet = $this->resolveLockedWalletForUser($actor->user_id);

        $this->creditPassengerWallet(
            $passengerWallet,
            $booking,
            $payment,
            $passenger,
            $actor,
            $amount,
            'booking_rejection_refund',
            'استرداد المبلغ إلى الراكب بعد رفض الحجز من السائق.'
        );

        $this->debitDriverWallet(
            $driverWallet,
            $booking,
            $payment,
            $actor,
            $passenger,
            $amount,
            'booking_rejection_reversal',
            'خصم من محفظة السائق بعد رفض الحجز وإعادة المبلغ إلى الراكب.'
        );

        $payment->update([
            'payment_status' => 'refunded',
            'failure_reason' => $reason ?: 'تم رفض الحجز من قبل السائق.',
        ]);
    }

    private function syncPaymentForAcceptance(Booking $booking, ?Payment $payment, User $actor): void
    {
        if (! $payment) {
            return;
        }

        if ($payment->payment_method !== 'electronic') {
            $payment->update([
                'payment_status' => 'pending',
                'failure_reason' => null,
            ]);

            return;
        }

        if ($payment->payment_status === 'paid') {
            return;
        }

        $amount = round((float) $payment->amount, 2);
        $passenger = $booking->passenger ?? User::query()->find($booking->passenger_id);

        if (! $passenger) {
            throw new RuntimeException('تعذر العثور على الراكب المرتبط بالحجز.');
        }

        $passengerWallet = $this->resolveLockedWalletForUser($passenger->user_id);
        $driverWallet = $this->resolveLockedWalletForUser($actor->user_id);

        if ((float) $passengerWallet->balance < $amount) {
            throw ValidationException::withMessages([
                'status' => 'رصيد الراكب غير كاف لإعادة تفعيل الحجز الإلكتروني.',
            ]);
        }

        if (! $payment->transaction_reference) {
            $payment->transaction_reference = Str::uuid()->toString();
        }

        $this->debitPassengerWallet(
            $passengerWallet,
            $booking,
            $payment,
            $passenger,
            $actor,
            $amount,
            'booking_payment',
            'خصم المبلغ مجدداً من الراكب بعد إعادة قبول الحجز.'
        );

        $this->creditDriverWallet(
            $driverWallet,
            $booking,
            $payment,
            $actor,
            $passenger,
            $amount,
            'booking_income',
            'إضافة المبلغ إلى محفظة السائق بعد إعادة قبول الحجز.'
        );

        $payment->update([
            'payment_status' => 'paid',
            'failure_reason' => null,
            'paid_at' => now(),
            'transaction_reference' => $payment->transaction_reference,
        ]);
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

    private function debitPassengerWallet(
        Wallet $wallet,
        Booking $booking,
        Payment $payment,
        User $passenger,
        User $driver,
        float $amount,
        string $receiptType,
        string $reason
    ): void
    {
        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance - $amount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $amount,
            'transaction_type' => 'debit',
            'status' => 'completed',
            'transaction_reference' => $payment->transaction_reference,
            'description' => $reason,
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $driver->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $passenger->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => $receiptType,
            'direction' => 'debit',
            'status' => 'paid',
            'amount' => $amount,
            'counterparty_user_id' => $driver->user_id,
            'counterparty_name' => $driver->full_name,
            'reason' => $reason,
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function creditPassengerWallet(
        Wallet $wallet,
        Booking $booking,
        Payment $payment,
        User $passenger,
        User $driver,
        float $amount,
        string $receiptType,
        string $reason
    ): void
    {
        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance + $amount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $amount,
            'transaction_type' => 'refund',
            'status' => 'completed',
            'transaction_reference' => $payment->transaction_reference ?? Str::uuid()->toString(),
            'description' => $reason,
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $driver->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $passenger->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => $receiptType,
            'direction' => 'credit',
            'status' => 'received',
            'amount' => $amount,
            'counterparty_user_id' => $driver->user_id,
            'counterparty_name' => $driver->full_name,
            'reason' => $reason,
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function creditDriverWallet(
        Wallet $wallet,
        Booking $booking,
        Payment $payment,
        User $driver,
        User $passenger,
        float $amount,
        string $receiptType,
        string $reason
    ): void
    {
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
            'description' => $reason,
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $driver->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $driver->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => $receiptType,
            'direction' => 'credit',
            'status' => 'received',
            'amount' => $amount,
            'counterparty_user_id' => $passenger->user_id,
            'counterparty_name' => $passenger->full_name,
            'reason' => $reason,
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function debitDriverWallet(
        Wallet $wallet,
        Booking $booking,
        Payment $payment,
        User $driver,
        User $passenger,
        float $amount,
        string $receiptType,
        string $reason
    ): void
    {
        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance - $amount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'related_booking_id' => $booking->booking_id,
            'amount' => $amount,
            'transaction_type' => 'adjustment',
            'status' => 'completed',
            'transaction_reference' => $payment->transaction_reference ?? Str::uuid()->toString(),
            'description' => $reason,
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $driver->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $driver->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_payment_id' => $payment->payment_id,
            'related_booking_id' => $booking->booking_id,
            'related_trip_id' => $booking->trip_id,
            'receipt_type' => $receiptType,
            'direction' => 'debit',
            'status' => 'paid',
            'amount' => $amount,
            'counterparty_user_id' => $passenger->user_id,
            'counterparty_name' => $passenger->full_name,
            'reason' => $reason,
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'payment_method' => 'electronic',
            ],
        ]);
    }

    private function baseDriverBookingsQuery(DriverProfile $driverProfile, ?string $status = null): Builder
    {
        $query = Booking::query()
            ->select('bookings.*')
            ->join('trips as trip', 'trip.trip_id', '=', 'bookings.trip_id')
            ->where('trip.driver_id', $driverProfile->user_id)
            ->with([
                'trip.status',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'passenger',
                'pickupPoint.governorate',
                'status',
                'attendanceStatus',
                'attendance',
                'payments',
                'statusLogs.toStatus',
                'cancellation',
            ]);

        if (in_array($status, ['accepted', 'rejected', 'canceled', 'completed'], true)) {
            $query->whereHas('status', function ($builder) use ($status) {
                $builder->where('status_key', $status);
            });
        }

        return $query;
    }

    private function transformGroupedBookings(Collection $bookings, User $actor): array
    {
        $unreadIds = $this->resolveUnreadBookingIds($actor, $bookings->pluck('booking_id')->all());
        $grouped = $bookings
            ->groupBy('trip_id')
            ->sortBy(function (Collection $tripBookings) {
                return optional($tripBookings->first()->trip?->departure_time)->timestamp ?? PHP_INT_MAX;
            })
            ->map(function (Collection $tripBookings) use ($unreadIds) {
                $trip = $tripBookings->first()->trip;

                return [
                    'trip_id' => $trip?->trip_id,
                    'departure_time' => optional($trip?->departure_time)->toIso8601String(),
                    'departure_location' => $trip?->startGovernorate?->name,
                    'arrival_location' => $trip?->endGovernorate?->name,
                    'trip_status' => $trip?->status?->status_key,
                    'requests_count' => $tripBookings->count(),
                    'unread_count' => $tripBookings->filter(fn (Booking $booking) => in_array($booking->booking_id, $unreadIds, true))->count(),
                    'bookings' => $tripBookings
                        ->sortByDesc('created_at')
                        ->map(fn (Booking $booking) => $this->transformBookingCard($booking, $unreadIds))
                        ->values(),
                ];
            })
            ->values();

        return [
            'items' => $grouped,
            'tabs' => [
                'all' => $bookings->count(),
                'accepted' => $bookings->where('status.status_key', 'accepted')->count(),
                'rejected' => $bookings->where('status.status_key', 'rejected')->count(),
                'canceled' => $bookings->where('status.status_key', 'canceled')->count(),
                'completed' => $bookings->where('status.status_key', 'completed')->count(),
            ],
        ];
    }

    private function transformBookingCard(Booking $booking, array $unreadIds): array
    {
        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'passenger_name' => $booking->passenger?->full_name,
            'seats_reserved' => (int) $booking->seats_reserved,
            'payment_method' => $booking->payment_method,
            'status' => [
                'key' => $booking->status?->status_key,
                'name' => $booking->status?->status_name,
            ],
            'sent_at' => optional($booking->created_at)->toIso8601String(),
            'is_new' => in_array($booking->booking_id, $unreadIds, true),
            'details_endpoint' => "/api/v1/driver/bookings/{$booking->booking_id}",
        ];
    }

    private function transformBookingDetails(Booking $booking): array
    {
        $payment = $booking->payments->sortByDesc('payment_id')->first();
        $latestRejectedLog = $booking->statusLogs
            ->sortByDesc('changed_at')
            ->first(fn ($log) => $log->toStatus?->status_key === 'rejected');

        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'passenger' => [
                'id' => $booking->passenger?->user_id,
                'full_name' => $booking->passenger?->full_name,
                'phone' => $booking->passenger?->phone,
                'rating' => $booking->passenger?->rating,
            ],
            'booking' => [
                'created_at' => optional($booking->created_at)->toIso8601String(),
                'seats_reserved' => (int) $booking->seats_reserved,
                'status' => [
                    'key' => $booking->status?->status_key,
                    'name' => $booking->status?->status_name,
                ],
                'attendance_status' => [
                    'key' => $booking->attendanceStatus?->status_key,
                    'name' => $booking->attendanceStatus?->status_name,
                ],
                'payment_method' => $payment?->payment_method ?? $booking->payment_method,
                'amount' => $payment?->amount !== null ? (float) $payment->amount : (float) $booking->total_amount,
                'rejection_reason' => $latestRejectedLog?->reason,
                'cancellation_reason' => $booking->cancellation?->reason,
            ],
            'pickup_point' => [
                'name' => $booking->pickupPoint?->point_name,
                'governorate' => $booking->pickupPoint?->governorate?->name,
                'address' => $booking->pickupPoint?->address,
                'latitude' => $booking->pickupPoint?->latitude !== null ? (float) $booking->pickupPoint->latitude : null,
                'longitude' => $booking->pickupPoint?->longitude !== null ? (float) $booking->pickupPoint->longitude : null,
                'meeting_time' => optional($booking->pickupPoint?->meeting_time)->toIso8601String(),
                'is_new' => (bool) $booking->pickupPoint?->is_new,
            ],
            'trip' => [
                'trip_id' => $booking->trip?->trip_id,
                'departure_time' => optional($booking->trip?->departure_time)->toIso8601String(),
                'departure_location' => $booking->trip?->startGovernorate?->name,
                'arrival_location' => $booking->trip?->endGovernorate?->name,
            ],
            'operations' => [
                'can_change_status' => ! in_array($booking->status?->status_key, ['canceled', 'completed'], true),
                'status_update_endpoint' => "/api/v1/driver/bookings/{$booking->booking_id}/status",
                'attendance_update_endpoint' => "/api/v1/driver/bookings/{$booking->booking_id}/attendance",
            ],
        ];
    }

    private function resolveUnreadBookingIds(User $actor, array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return DB::table('user_notifications')
            ->join('notifications', 'notifications.notification_id', '=', 'user_notifications.notification_id')
            ->where('user_notifications.user_id', $actor->user_id)
            ->where('user_notifications.is_read', false)
            ->where('notifications.notification_type', 'booking_requested')
            ->where('notifications.reference_type', Booking::class)
            ->whereIn('notifications.reference_id', $bookingIds)
            ->pluck('notifications.reference_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function markBookingRequestAsRead(User $actor, Booking $booking): void
    {
        $notificationIds = DB::table('notifications')
            ->where('notification_type', 'booking_requested')
            ->where('reference_type', Booking::class)
            ->where('reference_id', $booking->booking_id)
            ->pluck('notification_id');

        if ($notificationIds->isEmpty()) {
            return;
        }

        DB::table('user_notifications')
            ->where('user_id', $actor->user_id)
            ->whereIn('notification_id', $notificationIds)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    private function ensureBookingStatusCanBeChanged(Booking $booking): void
    {
        if (in_array($booking->status?->status_key, ['canceled', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن تغيير حالة الحجز إذا كان ملغى أو منتهياً.',
            ]);
        }
    }

    private function ensureTripCanAcceptBooking(Trip $trip, Booking $booking): void
    {
        if (in_array($trip->status?->status_key, [TripStatus::CANCELED, TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن قبول الحجز لأن الرحلة لم تعد متاحة.',
            ]);
        }

        $otherActiveBookings = Booking::query()
            ->with('status')
            ->where('trip_id', $trip->trip_id)
            ->where('booking_id', '!=', $booking->booking_id)
            ->get()
            ->filter(fn (Booking $item) => ! in_array($item->status?->status_key, ['canceled', 'rejected'], true));

        if ($booking->booking_type === 'private') {
            if ($otherActiveBookings->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن قبول الحجز الخاص لأن الرحلة مرتبطة بحجوزات أخرى فعالة.',
                ]);
            }

            return;
        }

        if ((int) $trip->available_seats < (int) $booking->seats_reserved) {
            throw ValidationException::withMessages([
                'status' => 'لا توجد مقاعد كافية لإعادة قبول هذا الحجز.',
            ]);
        }

        if ($otherActiveBookings->contains(fn (Booking $item) => $item->booking_type === 'private')) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن قبول هذا الحجز لأن الرحلة أصبحت خاصة.',
            ]);
        }
    }

    private function restoreTripCapacity(Trip $trip, Booking $booking): void
    {
        if ($booking->booking_type === 'private') {
            $trip->update([
                'available_seats' => (int) $trip->total_seats,
                'allow_shared' => $trip->shared_price !== null,
                'allow_private' => $trip->private_price !== null,
                'is_private_booked' => false,
            ]);

            return;
        }

        $trip->update([
            'available_seats' => min((int) $trip->total_seats, (int) $trip->available_seats + (int) $booking->seats_reserved),
            'allow_shared' => true,
            'allow_private' => false,
            'is_private_booked' => false,
        ]);
    }

    private function reserveTripCapacity(Trip $trip, Booking $booking): void
    {
        if ($booking->booking_type === 'private') {
            $trip->update([
                'available_seats' => 0,
                'allow_shared' => false,
                'allow_private' => true,
                'is_private_booked' => true,
            ]);

            return;
        }

        $trip->update([
            'available_seats' => max(0, (int) $trip->available_seats - (int) $booking->seats_reserved),
            'allow_shared' => true,
            'allow_private' => false,
            'is_private_booked' => false,
        ]);
    }

    private function resolveBookingStatus(string $statusKey): BookingStatus
    {
        $status = BookingStatus::query()
            ->where('status_key', $statusKey)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw new RuntimeException('حالة الحجز المطلوبة غير موجودة.', 500);
        }

        return $status;
    }

    private function resolveAttendanceStatus(string $statusKey): BookingAttendanceStatus
    {
        $status = BookingAttendanceStatus::query()
            ->where('status_key', $statusKey)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw new RuntimeException('حالة الحضور المطلوبة غير موجودة.', 500);
        }

        return $status;
    }

    private function applyNoShowConsequences(Booking $booking, User $actor, ?string $notes): array
    {
        $penaltyAmount = $booking->payment_method === 'electronic'
            ? (float) $booking->total_amount
            : 0.0;
        $ratingPenalty = 0.3;

        if ($booking->payment_method === 'cash') {
            $this->createCashOnlyRestriction(
                $booking->passenger,
                'تم تقييد الدفع النقدي بسبب تسجيل الراكب كغائب في رحلة سابقة.'
            );
        }

        $this->applyPassengerRatingPenalty(
            $booking->passenger,
            $booking,
            $ratingPenalty,
            'خصم تقييم بسبب عدم الحضور في الرحلة.'
        );

        return [$penaltyAmount, $ratingPenalty];
    }

    private function createCashOnlyRestriction(?User $passenger, string $reason): void
    {
        if (! $passenger) {
            return;
        }

        $activeRestriction = AccountRestriction::query()
            ->where('user_id', $passenger->user_id)
            ->where('restriction_type', 'cash_block')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->first();

        if ($activeRestriction) {
            return;
        }

        AccountRestriction::create([
            'user_id' => $passenger->user_id,
            'restriction_type' => 'cash_block',
            'start_date' => now(),
            'end_date' => null,
            'reason' => $reason,
            'is_active' => true,
        ]);
    }

    private function applyPassengerRatingPenalty(?User $passenger, Booking $booking, float $amount, string $reason): void
    {
        if (! $passenger) {
            return;
        }

        $currentRating = (float) ($passenger->rating ?? User::DEFAULT_RATING);
        $newRating = max(User::MIN_RATING, round($currentRating - $amount, 2));

        $passenger->update([
            'rating' => $newRating,
            'rating_last_updated' => now(),
        ]);

        DB::table('passenger_rating_logs')->insert([
            'user_id' => $passenger->user_id,
            'booking_id' => $booking->booking_id,
            'rating_change' => -1 * abs($amount),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function applyPassengerRatingBonus(?User $passenger, Booking $booking, float $amount, string $reason): void
    {
        if (! $passenger) {
            return;
        }

        $currentRating = (float) ($passenger->rating ?? User::DEFAULT_RATING);
        $newRating = min(User::MAX_RATING, round($currentRating + $amount, 2));

        $passenger->update([
            'rating' => $newRating,
            'rating_last_updated' => now(),
        ]);

        DB::table('passenger_rating_logs')->insert([
            'user_id' => $passenger->user_id,
            'booking_id' => $booking->booking_id,
            'rating_change' => abs($amount),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function notifyPassengerStatusChange(Booking $booking, User $actor, string $statusKey, ?string $reason): void
    {
        if (! $booking->passenger_id) {
            return;
        }

        $title = $statusKey === 'accepted' ? 'تم قبول الحجز' : 'تم رفض الحجز';
        $body = $statusKey === 'accepted'
            ? "قام السائق بتأكيد الحجز رقم {$booking->booking_code}."
            : "قام السائق برفض الحجز رقم {$booking->booking_code}. السبب: {$reason}";

        $notification = Notification::create([
            'title' => $title,
            'body' => $body,
            'notification_type' => "driver_booking_{$statusKey}",
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        $this->sendNotificationToUser($notification, $booking->passenger_id);
    }

    private function notifyPassengerAttendanceMarked(
        Booking $booking,
        User $actor,
        BookingAttendanceStatus $attendanceStatus,
        ?string $notes
    ): void {
        if (! $booking->passenger_id) {
            return;
        }

        $notification = Notification::create([
            'title' => 'تحديث حالة الحضور',
            'body' => "تم تسجيل حالة حضورك في الحجز رقم {$booking->booking_code} كـ {$attendanceStatus->status_name}.",
            'notification_type' => 'booking_attendance_updated',
            'reference_type' => Booking::class,
            'reference_id' => $booking->booking_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
            'target_governorate_id' => $booking->pickupPoint?->governorate_id,
        ]);

        $this->sendNotificationToUser($notification, $booking->passenger_id);
    }

    private function sendNotificationToUser(Notification $notification, int $userId): void
    {
        UserNotification::firstOrCreate(
            [
                'notification_id' => $notification->notification_id,
                'user_id' => $userId,
            ],
            [
                'is_read' => false,
                'is_sent' => true,
                'sent_at' => now(),
            ]
        );
    }
}
