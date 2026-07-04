<?php

namespace App\Services;

use App\Events\BookingStatusChanged;
use App\Events\TripStatusChanged;
use App\Models\Booking;
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
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use RuntimeException;

class DriverTripManagementService
{
    private const COMPLETION_PROXIMITY_METERS = 500.0;
    private const LIVE_TRACKING_FRESHNESS_MINUTES = 5;
    private const AUTO_COMPLETE_TRACKING_STALE_MINUTES = 15;

    public function __construct(
        private readonly ReceiptService $receiptService,
        private readonly CommissionRateService $commissionRateService,
        private readonly AuditLogService $auditLogService,
        private readonly WalletTransactionService $walletTransactionService,
        private readonly TripTrackingService $tripTrackingService,
        private readonly TripClusterService $tripClusterService,
        private readonly NotificationDispatchService $notifications
    ) {
    }

    public function listTrips(User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        $trips = $this->baseDriverTripsQuery($driverProfile)
            ->orderBy('departure_time')
            ->get()
            ->map(fn (Trip $trip) => $this->transformCardTrip($trip));
            
        return [
            'items' => $trips->values(),
            'counts' => [
                'all' => $trips->count(),
                'pending' => $trips->where('classification.key', 'pending')->count(),
                'current' => $trips->where('classification.key', 'current')->count(),
                'completed' => $trips->where('classification.key', 'completed')->count(),
                'canceled' => $trips->where('classification.key', 'canceled')->count(),
            ],
        ];
    }

    public function listPendingTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'pending'),
        ];
    }

    public function listCurrentTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'current'),
        ];
    }

    public function listCompletedTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'completed'),
        ];
    }

    public function listCanceledTrips(User $actor): array
    {
        return [
            'items' => $this->filteredTrips($actor, 'canceled'),
        ];
    }

    public function showTripDetails(int $tripId, User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.passenger',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.pickupPoint.governorate',
                'bookings.pickupPoint.tripPoint',
                'bookings.payments',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        return $this->transformTripDetails($trip);
    }

    public function showTripAttendance(int $tripId, User $actor): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.passenger',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.pickupPoint',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        return [
            'trip_id' => $trip->trip_id,
            'attendance' => [
                'items' => $trip->bookings->map(function (Booking $booking) {
                    return [
                        'booking_id' => $booking->booking_id,
                        'booking_code' => $booking->booking_code,
                        'passenger_name' => $booking->passenger?->full_name,
                        'passenger_phone' => $booking->passenger?->phone,
                        'passenger_image' => $booking->passenger?->profile_photo,
                        'passenger_rating' => $booking->passenger?->rating !== null ? (float) $booking->passenger->rating : null,
                        'pickup_point' => $booking->pickupPoint?->point_name ?? $booking->pickupPoint?->address,
                        'booking_status' => $booking->status?->status_name,
                        'attendance_status' => $booking->attendanceStatus?->status_name,
                        'attendance_status_key' => $booking->attendanceStatus?->status_key,
                    ];
                })->values(),
            ],
        ];
    }

    public function cancelTrip(int $tripId, User $actor, ?string $reason): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.status',
                'bookings.passenger',
                'bookings.payments',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        $tripStatusKey = $trip->status?->status_key;
        if (in_array($tripStatusKey, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED, TripStatus::CANCELED], true)) {
            throw new RuntimeException('لا يمكن إلغاء الرحلات المنجزة أو الملغاة مسبقاً.', 422);
        }

        $canceledTripStatus = $this->resolveTripStatus(TripStatus::CANCELED);
        $canceledBookingStatus = $this->resolveBookingStatus('canceled');
        $reasonText = $reason ?: 'تم إلغاء الرحلة من قبل السائق.';

        DB::transaction(function () use ($trip, $actor, $canceledTripStatus, $canceledBookingStatus, $reasonText) {
            $oldStatusId = $trip->status_id;

            $trip->forceFill([
                'status_id' => $canceledTripStatus->status_id,
            ])->save();

            event(new TripStatusChanged(
                $trip,
                $oldStatusId,
                $canceledTripStatus->status_id,
                $actor->user_id,
                $reasonText
            ));

            $this->tripTrackingService->stopTracking($trip, now());

            foreach ($trip->bookings as $booking) {
                $currentStatusKey = $booking->status?->status_key;

                if (in_array($currentStatusKey, ['canceled', 'completed'], true)) {
                    continue;
                }

                $fromStatusId = $booking->status_id;

                $booking->forceFill([
                    'status_id' => $canceledBookingStatus->status_id,
                    'canceled_at' => now(),
                ])->save();

                $payment = Payment::query()
                    ->where('booking_id', $booking->booking_id)
                    ->lockForUpdate()
                    ->first();

                $walletRefundAmount = $this->syncPaymentForTripCancellation(
                    $booking,
                    $payment,
                    $actor,
                    $reasonText
                );

                BookingStatusLog::create([
                    'booking_id' => $booking->booking_id,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $canceledBookingStatus->status_id,
                    'changed_by' => $actor->user_id,
                    'reason' => $reasonText,
                    'changed_at' => now(),
                ]);

                event(new BookingStatusChanged(
                    $booking,
                    $fromStatusId,
                    $canceledBookingStatus->status_id,
                    $actor->user_id,
                    $reasonText
                ));

                BookingCancellation::updateOrCreate(
                    ['booking_id' => $booking->booking_id],
                    [
                        'canceled_by' => $actor->user_id,
                        'reason' => $reasonText,
                        'cancellation_time' => now(),
                        'hours_before_departure' => $this->hoursBeforeDeparture($trip),
                        'penalty_percentage' => 0,
                        'penalty_amount' => 0,
                        'wallet_refund_amount' => $walletRefundAmount,
                        'rating_penalty' => 0,
                    ]
                );

                if ($booking->passenger_id) {
                    $notification = Notification::create([
                        'title' => 'إلغاء الرحلة',
                        'body' => "تم إلغاء الرحلة رقم {$trip->trip_id} من قبل السائق. السبب: {$reasonText}",
                        'notification_type' => 'trip_canceled_by_driver',
                        'reference_type' => 'trip',
                        'reference_id' => $trip->trip_id,
                        'created_by' => $actor->user_id,
                        'target_role' => Role::ROLE_PASSENGER,
                    ]);

                    $this->notifications->sendExistingToUser($notification, $booking->passenger_id, [
                        'trip_id' => $trip->trip_id,
                        'booking_id' => $booking->booking_id,
                    ]);
                }
            }

            $this->tripClusterService->refreshClusterAvailability($trip->cluster_id);
        });

        return $this->showTripDetails($tripId, $actor);
    }

    public function startTrip(int $tripId, User $actor, ?string $notes = null): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.status',
                'bookings.passenger',
                'bookings.pickupPoint',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        $tripStatusKey = $trip->status?->status_key;

        if ($tripStatusKey === TripStatus::ACTIVE) {
            throw new RuntimeException('الرحلة نشطة بالفعل.', 422);
        }

        if (in_array($tripStatusKey, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED, TripStatus::CANCELED], true)) {
            throw new RuntimeException('لا يمكن بدء رحلة منتهية أو ملغاة.', 422);
        }

        if ($trip->departure_time && now()->lt(Carbon::parse($trip->departure_time))) {
            throw new RuntimeException('لا يمكن بدء الرحلة قبل دخول وقت الانطلاق.', 422);
        }

        $activeTripStatus = $this->resolveTripStatus(TripStatus::ACTIVE);

        DB::transaction(function () use ($trip, $actor, $activeTripStatus, $notes) {
            $oldStatusId = $trip->status_id;
            $startedAt = now();

            $trip->forceFill([
                'status_id' => $activeTripStatus->status_id,
                'actual_start_time' => $startedAt,
            ])->save();

            event(new TripStatusChanged(
                $trip,
                $oldStatusId,
                $activeTripStatus->status_id,
                $actor->user_id,
                $notes
            ));

            $this->tripTrackingService->activateTracking($trip, $startedAt);

            $this->auditLogService->log(
                $actor,
                'trip.started',
                Trip::class,
                $trip->trip_id,
                [
                    'status_id' => $oldStatusId,
                    'actual_start_time' => null,
                    'is_tracking_active' => false,
                ],
                [
                    'status_id' => $activeTripStatus->status_id,
                    'actual_start_time' => $trip->actual_start_time?->toIso8601String(),
                    'is_tracking_active' => true,
                    'notes' => $notes,
                ],
                "Trip {$trip->trip_id} started by {$actor->full_name}."
            );

            $this->notifyPassengersTripStarted($trip, $actor);
        });

        return $this->showTripDetails($tripId, $actor);
    }

    public function completeTrip(int $tripId, User $actor, ?string $notes = null, ?float $latitude = null, ?float $longitude = null): array
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $trip = $this->baseDriverTripsQuery($driverProfile)
            ->with([
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.passenger',
                'bookings.payments',
                'commissionRate',
            ])
            ->where('trip_id', $tripId)
            ->first();

        if (! $trip) {
            throw new RuntimeException('الرحلة المطلوبة غير موجودة أو لا تتبع لهذا السائق.', 404);
        }

        $tripStatusKey = $trip->status?->status_key;
        if (in_array($tripStatusKey, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED], true)) {
            return $this->showTripDetails($tripId, $actor);
        }

        if ($tripStatusKey === TripStatus::CANCELED) {
            throw new RuntimeException('لا يمكن إنهاء رحلة مكتملة أو ملغاة مسبقاً.', 422);
        }

        if ($trip->departure_time && now()->lt(Carbon::parse($trip->departure_time))) {
            throw new RuntimeException('لا يمكن إنهاء الرحلة قبل وقت انطلاقها.', 422);
        }

        $completionContext = $this->resolveManualCompletionEligibility($trip, $latitude, $longitude);

        $completedTripStatus = $this->resolveTripStatus(TripStatus::COMPLETED);
        $completedBookingStatus = $this->resolveBookingStatus('completed');

        DB::transaction(function () use ($trip, $actor, $completedTripStatus, $completedBookingStatus, $notes, $completionContext) {
            $trip = Trip::query()
                ->with([
                    'status',
                    'bookings.status',
                    'bookings.attendanceStatus',
                    'bookings.passenger',
                    'bookings.payments',
                    'commissionRate',
                ])
                ->where('trip_id', $trip->trip_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTripStatusKey = $trip->status?->status_key;
            if (in_array($lockedTripStatusKey, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED], true)) {
                return;
            }

            if ($lockedTripStatusKey === TripStatus::CANCELED) {
                throw new RuntimeException('Cannot complete a canceled trip.', 422);
            }

            $oldTripState = [
                'status_id' => $trip->status_id,
                'gross_revenue_amount' => $trip->gross_revenue_amount,
                'commission_amount' => $trip->commission_amount,
                'net_revenue_amount' => $trip->net_revenue_amount,
                'completion_mode' => $trip->completion_mode,
                'completion_reason' => $trip->completion_reason,
            ];

            $completedAt = now();

            $grossRevenue = $this->commissionRateService->calculateTripGrossRevenue($trip);
            $commissionPercentage = round((float) ($trip->commission_percentage ?? 0), 2);
            $commissionAmount = $this->commissionRateService->calculateCommissionAmount($grossRevenue, $commissionPercentage);
            $netRevenue = round($grossRevenue - $commissionAmount, 2);

            $driverWallet = $this->resolveLockedWalletForUser($actor->user_id);

            if ((float) $driverWallet->balance < $commissionAmount) {
                throw new RuntimeException('رصيد محفظة السائق غير كافٍ لخصم عمولة الرحلة المكتملة.', 422);
            }

            $this->markTripBookingsAsCompleted($trip, $completedBookingStatus, $actor, $notes);

            if ($commissionAmount > 0) {
                $this->deductCompletedTripCommission(
                    $trip,
                    $driverWallet,
                    $actor,
                    $grossRevenue,
                    $commissionAmount,
                    $netRevenue
                );
            }

            $this->tripTrackingService->stopTracking(
                $trip,
                $completedAt,
                $completionContext['latitude'],
                $completionContext['longitude']
            );

            $trip->forceFill([
                'status_id' => $completedTripStatus->status_id,
                'gross_revenue_amount' => $grossRevenue,
                'commission_amount' => $commissionAmount,
                'net_revenue_amount' => $netRevenue,
                'completed_at' => $completedAt,
                'completion_mode' => 'driver',
                'completion_reason' => $completionContext['reason'],
                'tracking_stopped_at' => $completedAt,
                'completion_latitude' => $completionContext['latitude'],
                'completion_longitude' => $completionContext['longitude'],
            ])->save();

            event(new TripStatusChanged(
                $trip,
                $oldTripState['status_id'],
                $completedTripStatus->status_id,
                $actor->user_id,
                $completionContext['reason']
            ));

            $this->tripClusterService->refreshClusterAvailability($trip->cluster_id);

            $this->auditLogService->log(
                $actor,
                'trip.completed',
                Trip::class,
                $trip->trip_id,
                $oldTripState,
                [
                    'status_id' => $completedTripStatus->status_id,
                    'gross_revenue_amount' => $grossRevenue,
                    'commission_amount' => $commissionAmount,
                    'net_revenue_amount' => $netRevenue,
                    'completed_at' => $completedAt->toIso8601String(),
                    'completion_mode' => 'driver',
                    'completion_reason' => $completionContext['reason'],
                    'completion_source' => $completionContext['source'],
                ],
                "Trip {$trip->trip_id} completed by {$actor->full_name}."
            );

            $this->notifyTripCompleted($trip->fresh(['bookings.status', 'bookings.passenger']), $actor, false);
        });

        return $this->showTripDetails($tripId, $actor);
    }

    public function autoCompleteEligibleTrips(): array
    {
        $activeStatusId = $this->resolveTripStatus(TripStatus::ACTIVE)->status_id;
        $autoCompletedTripStatus = $this->resolveTripStatus(TripStatus::AUTO_COMPLETED);
        $completedBookingStatus = $this->resolveBookingStatus('completed');

        $trips = Trip::query()
            ->where('status_id', $activeStatusId)
            ->with([
                'status',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.passenger',
                'bookings.payments',
                'points',
                'driver.user',
            ])
            ->get()
            ->map(fn (Trip $trip) => [
                'trip' => $trip,
                'context' => $this->resolveAutoCompletionContext($trip),
            ])
            ->filter(fn (array $item) => $item['context'] !== null)
            ->values();

        $completedTripIds = [];

        foreach ($trips as $item) {
            /** @var Trip $trip */
            $trip = $item['trip'];
            $completionContext = $item['context'];

            DB::transaction(function () use ($trip, $autoCompletedTripStatus, $completedBookingStatus, $completionContext) {
                $oldTripState = [
                    'status_id' => $trip->status_id,
                    'gross_revenue_amount' => $trip->gross_revenue_amount,
                    'commission_amount' => $trip->commission_amount,
                    'net_revenue_amount' => $trip->net_revenue_amount,
                    'completion_mode' => $trip->completion_mode,
                    'completion_reason' => $trip->completion_reason,
                ];

                $completedAt = now();
                $grossRevenue = $this->commissionRateService->calculateTripGrossRevenue($trip);
                $commissionPercentage = round((float) ($trip->commission_percentage ?? 0), 2);
                $commissionAmount = $this->commissionRateService->calculateCommissionAmount($grossRevenue, $commissionPercentage);
                $netRevenue = round($grossRevenue - $commissionAmount, 2);

                $driverUserId = $trip->driver_id;
                $driverWallet = $this->resolveLockedWalletForUser($driverUserId);

                if ((float) $driverWallet->balance < $commissionAmount) {
                    throw new RuntimeException('رصيد محفظة السائق غير كافٍ لخصم عمولة الرحلة المنتهية تلقائياً.', 422);
                }

                $this->markTripBookingsAsCompleted($trip, $completedBookingStatus, null, 'تم إغلاق الحجوزات عند الإنهاء التلقائي للرحلة.');

            if ($commissionAmount > 0) {
                $driver = $trip->driver?->user ?? User::query()->findOrFail($driverUserId);

                    $this->deductCompletedTripCommission(
                        $trip,
                        $driverWallet,
                        $driver,
                        $grossRevenue,
                        $commissionAmount,
                        $netRevenue
                );
            }

                $this->tripTrackingService->stopTracking(
                    $trip,
                    $completedAt,
                    $completionContext['latitude'],
                    $completionContext['longitude']
                );

            $trip->forceFill([
                'status_id' => $autoCompletedTripStatus->status_id,
                    'gross_revenue_amount' => $grossRevenue,
                    'commission_amount' => $commissionAmount,
                    'net_revenue_amount' => $netRevenue,
                    'completed_at' => $completedAt,
                    'completion_mode' => 'system',
                    'completion_reason' => $completionContext['reason'],
                    'tracking_stopped_at' => $completedAt,
                    'completion_latitude' => $completionContext['latitude'],
                    'completion_longitude' => $completionContext['longitude'],
                ])->save();

                event(new TripStatusChanged(
                    $trip,
                    $oldTripState['status_id'],
                    $autoCompletedTripStatus->status_id,
                    null,
                    $completionContext['reason']
                ));

                $this->tripClusterService->refreshClusterAvailability($trip->cluster_id);

                $this->auditLogService->log(
                    null,
                    'trip.auto_completed',
                    Trip::class,
                    $trip->trip_id,
                    $oldTripState,
                    [
                        'status_id' => $autoCompletedTripStatus->status_id,
                        'gross_revenue_amount' => $grossRevenue,
                        'commission_amount' => $commissionAmount,
                        'net_revenue_amount' => $netRevenue,
                        'completed_at' => $completedAt->toIso8601String(),
                        'completion_mode' => 'system',
                        'completion_reason' => $completionContext['reason'],
                        'completion_source' => $completionContext['source'],
                    ],
                    "Trip {$trip->trip_id} auto-completed by system."
                );

                $this->notifyTripCompleted($trip->fresh(['bookings.status', 'bookings.passenger']), null, true);
            });

            $completedTripIds[] = $trip->trip_id;
        }

        return [
            'count' => count($completedTripIds),
            'trip_ids' => $completedTripIds,
        ];
    }

    private function syncPaymentForTripCancellation(Booking $booking, ?Payment $payment, User $driver, string $reason): float
    {
        if (! $payment) {
            return 0.0;
        }

        if ($payment->payment_method !== 'electronic') {
            $payment->update([
                'payment_status' => 'canceled',
                'failure_reason' => $reason,
            ]);

            return 0.0;
        }

        if (in_array($payment->payment_status, ['refunded', 'canceled'], true)) {
            return 0.0;
        }

        $passenger = $booking->passenger ?? User::query()->find($booking->passenger_id);

        if (! $passenger) {
            throw new RuntimeException('تعذر العثور على الراكب المرتبط بالحجز أثناء إلغاء الرحلة.');
        }

        $amount = round((float) $payment->amount, 2);
        $passengerWallet = $this->resolveLockedWalletForUser($passenger->user_id);
        $driverWallet = $this->resolveLockedWalletForUser($driver->user_id);

        $this->creditPassengerWallet(
            $passengerWallet,
            $booking,
            $payment,
            $passenger,
            $driver,
            $amount,
            'trip_cancellation_refund',
            'استرداد إلى الراكب بعد إلغاء الرحلة من السائق.'
        );

        $this->debitDriverWallet(
            $driverWallet,
            $booking,
            $payment,
            $driver,
            $passenger,
            $amount,
            'trip_cancellation_reversal',
            'خصم من محفظة السائق بعد إلغاء الرحلة وإعادة المبلغ إلى الراكب.'
        );

        $payment->update([
            'payment_status' => 'refunded',
            'failure_reason' => $reason,
        ]);

        return $amount;
    }

    private function filteredTrips(User $actor, string $classification): Collection
    {
        $driverProfile = $this->resolveDriverProfile($actor);

        return $this->baseDriverTripsQuery($driverProfile)
            ->orderBy('departure_time')
            ->get()
            ->map(fn (Trip $trip) => $this->transformCardTrip($trip))
            ->filter(fn (array $trip) => data_get($trip, 'classification.key') === $classification)
            ->values();
    }

    private function resolveDriverProfile(User $actor): DriverProfile
    {
        $actor->loadMissing('driverProfile');

        if (! $actor->driverProfile) {
            throw new RuntimeException('المستخدم الحالي لا يملك ملف سائق.', 403);
        }

        return $actor->driverProfile;
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

    private function baseDriverTripsQuery(DriverProfile $driverProfile): Builder
    {
        return Trip::query()
            ->where('driver_id', $driverProfile->user_id)
            ->with([
                'status',
                'commissionRate',
                'startGovernorate',
                'endGovernorate',
                'points',
                'driver.user',
                'driver.vehicles.category',
                'driver.vehicles.images',
                'bookings.status',
                'bookings.attendanceStatus',
                'bookings.passenger',
                'bookings.pickupPoint',
            ])
            ->withCount('bookings');
    }

    private function transformCardTrip(Trip $trip): array
    {
        $classification = $this->classifyTrip($trip);
        $vehicle = $trip->driver?->vehicles->first();
        $expectedArrival = $this->expectedArrival($trip);

        return [
            'trip_id' => $trip->trip_id,
            'classification' => $classification,
            
            'trip_type' => $this->tripType($trip),
            'card' => [
                'vehicle_image' => $vehicle?->images->first()?->image_url,
                'departure_location' => $trip->startGovernorate?->name,
                'arrival_location' => $trip->endGovernorate?->name,
                'departure_time' => optional($trip->departure_time)->toIso8601String(),
                'expected_arrival_time' => $expectedArrival?->toIso8601String(),
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'available_seats' => (int) $trip->available_seats,
                'details_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}",
            ],
        ];
    }

    private function transformTripDetails(Trip $trip): array
    {
        $expectedArrival = $this->expectedArrival($trip);
        $points = $trip->points->sortBy('sequence_order')->values();
        $vehicle = $trip->driver?->vehicles->first();

        return [
            'trip_id' => $trip->trip_id,
            'classification' => $this->classifyTrip($trip),
            
            'trip_details' => [
                'departure_time' => optional($trip->departure_time)->toIso8601String(),
                'expected_arrival_time' => $expectedArrival?->toIso8601String(),
                'departure_location' => $trip->startGovernorate?->name,
                'arrival_location' => $trip->endGovernorate?->name,
                'route_polyline' => $trip->route_polyline,
                'trip_type' => $this->tripType($trip),
                'shared_price' => $trip->shared_price !== null ? (float) $trip->shared_price : null,
                'private_price' => $trip->private_price !== null ? (float) $trip->private_price : null,
                'remaining_seats' => (int) $trip->available_seats,
                'commission' => [
                    'rate_id' => $trip->commission_rate_id,
                    'percentage' => (float) ($trip->commission_percentage ?? 0),
                    'max_commission_amount' => (float) ($trip->max_commission_amount ?? 0),
                    'gross_revenue_amount' => $trip->gross_revenue_amount !== null ? (float) $trip->gross_revenue_amount : null,
                    'commission_amount' => $trip->commission_amount !== null ? (float) $trip->commission_amount : null,
                    'net_revenue_amount' => $trip->net_revenue_amount !== null ? (float) $trip->net_revenue_amount : null,
                ],
                'actual_start_time' => optional($trip->actual_start_time)->toIso8601String(),
                'completed_at' => optional($trip->completed_at)->toIso8601String(),
                'completion' => [
                    'mode' => $trip->completion_mode,
                    'reason' => $trip->completion_reason,
                    'tracking_stopped_at' => optional($trip->tracking_stopped_at)->toIso8601String(),
                    'location' => [
                        'latitude' => $trip->completion_latitude !== null ? (float) $trip->completion_latitude : null,
                        'longitude' => $trip->completion_longitude !== null ? (float) $trip->completion_longitude : null,
                    ],
                ],
                'tracking' => [
                    'is_tracking_active' => (bool) $trip->is_tracking_active,
                    'tracking_started_at' => optional($trip->tracking_started_at)->toIso8601String(),
                    'tracking_stopped_at' => optional($trip->tracking_stopped_at)->toIso8601String(),
                    'last_location_at' => optional($trip->last_location_at)->toIso8601String(),
                    'last_position' => $trip->last_latitude !== null && $trip->last_longitude !== null
                        ? [
                            'latitude' => (float) $trip->last_latitude,
                            'longitude' => (float) $trip->last_longitude,
                            'speed_kmh' => $trip->last_speed_kmh !== null ? (float) $trip->last_speed_kmh : null,
                            'heading' => $trip->last_heading !== null ? (float) $trip->last_heading : null,
                            'accuracy_meters' => $trip->last_accuracy_meters !== null ? (float) $trip->last_accuracy_meters : null,
                        ]
                        : null,
                ],
                'vehicle' => [
                    'type' => $vehicle?->car_type,
                    'model' => $vehicle?->certified_agency,
                    'vehicle_category' => $vehicle?->categoryPayload(),
                    'image' => $vehicle?->images->first()?->image_url,
                ],
                'points' => $points->map(function ($point) {
                    return [
                        'point_id' => $point->point_id,
                        'type' => $point->point_type,
                        'address' => $point->address,
                        'note' => $point->note,
                        'latitude' => (float) $point->latitude,
                        'longitude' => (float) $point->longitude,
                        'sequence_order' => (int) $point->sequence_order,
                        'expected_arrival_time' => optional($point->expected_arrival_time)->toIso8601String(),
                    ];
                })->values(),
                'bookings_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/bookings",
                'attendance_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/attendance",
                'tracking_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/tracking",
                'location_update_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/location",
                'start_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/start",
                'complete_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/complete",
                'cancel_endpoint' => "/api/v1/driver/trips/{$trip->trip_id}/cancel",
            ],
        ];
    }

    private function transformBookingDetails(Booking $booking): array
    {
        $payment = $booking->payments->sortByDesc('payment_id')->first();

        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'booking_type' => $booking->booking_type,
            'seats_reserved' => (int) $booking->seats_reserved,
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
                'latitude' => $booking->pickupPoint?->latitude !== null ? (float) $booking->pickupPoint->latitude : null,
                'longitude' => $booking->pickupPoint?->longitude !== null ? (float) $booking->pickupPoint->longitude : null,
                'governorate' => $booking->pickupPoint?->governorate?->name,
            ],
            'payment' => [
                'method' => $payment?->payment_method ?? $booking->payment_method,
                'status' => $payment?->payment_status,
                'amount' => $payment?->amount !== null ? (float) $payment->amount : (float) $booking->total_amount,
            ],
            'status' => [
                'key' => $booking->status?->status_key,
                'name' => $booking->status?->status_name,
            ],
            'attendance' => [
                'key' => $booking->attendanceStatus?->status_key,
                'name' => $booking->attendanceStatus?->status_name,
            ],
            'notes' => $booking->notes,
        ];
    }

    private function classifyTrip(Trip $trip): array
    {
        $statusKey = $trip->status?->status_key;
        $departure = $trip->departure_time ? Carbon::parse($trip->departure_time) : null;

        if ($statusKey === TripStatus::CANCELED) {
            return ['key' => 'canceled', 'name' => 'ملغاة'];
        }

        if (in_array($statusKey, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED], true)) {
            return ['key' => 'completed', 'name' => 'منجزة'];
        }

        if ($statusKey === TripStatus::ACTIVE || ($statusKey === TripStatus::PENDING && $departure && now()->greaterThanOrEqualTo($departure))) {
            return ['key' => 'current', 'name' => 'حالية'];
        }

        return ['key' => 'pending', 'name' => 'قيد الانتظار'];
    }

    private function tripType(Trip $trip): string
    {
        if ($trip->allow_shared && $trip->allow_private) {
            return 'both';
        }

        if ($trip->allow_private) {
            return 'private';
        }

        return 'shared';
    }

    private function expectedArrival(Trip $trip): ?Carbon
    {
        if (! $trip->departure_time) {
            return null;
        }

        return Carbon::parse($trip->departure_time)->addMinutes((int) $trip->estimated_duration_minutes);
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

    private function hoursBeforeDeparture(Trip $trip): float
    {
        if (! $trip->departure_time) {
            return 0;
        }

        return round(now()->floatDiffInHours(Carbon::parse($trip->departure_time), false), 2);
    }

    private function ensureDriverCompletionEligibility(Trip $trip, ?float $latitude, ?float $longitude): void
    {
        if ($latitude === null || $longitude === null) {
            $expectedArrival = $this->expectedArrival($trip);

            if ($expectedArrival && now()->lt($expectedArrival)) {
                throw ValidationException::withMessages([
                    'latitude' => 'لا يمكن إنهاء الرحلة دون موقع السائق قبل وقت الوصول المتوقع.',
                ]);
            }

            return;
        }

        $endPoint = $this->resolveTripEndPoint($trip);

        if (! $endPoint) {
            throw new RuntimeException('لا يمكن التحقق من نقطة نهاية الرحلة لعدم وجود نقطة وصول صالحة.', 422);
        }

        $distanceMeters = $this->calculateDistanceMeters(
            $latitude,
            $longitude,
            (float) $endPoint->latitude,
            (float) $endPoint->longitude
        );

        if ($distanceMeters > 500) {
            throw ValidationException::withMessages([
                'latitude' => 'لا يمكن إنهاء الرحلة لأن السائق ليس قريباً بما يكفي من نقطة النهاية.',
            ]);
        }
    }

    private function shouldAutoCompleteTrip(Trip $trip): bool
    {
        $statusKey = $trip->status?->status_key;

        if ($statusKey !== TripStatus::ACTIVE) {
            return false;
        }

        $expectedArrival = $this->expectedArrival($trip);

        if (! $expectedArrival) {
            return false;
        }

        return now()->greaterThanOrEqualTo($expectedArrival->copy()->addHours(2));
    }

    private function resolveManualCompletionEligibility(Trip $trip, ?float $latitude, ?float $longitude): array
    {
        $completionContext = $this->resolveDriverCompletionContext($trip, $latitude, $longitude);

        if ($completionContext['source'] === 'time_fallback') {
            $expectedArrival = $this->expectedArrival($trip);

            if ($expectedArrival && now()->lt($expectedArrival)) {
                throw ValidationException::withMessages([
                    'latitude' => 'لا يمكن إنهاء الرحلة دون موقع فعلي قبل وقت الوصول المتوقع.',
                ]);
            }

            return $completionContext;
        }

        $endPoint = $this->resolveTripEndPoint($trip);

        if (! $endPoint) {
            throw new RuntimeException('لا يمكن التحقق من نقطة نهاية الرحلة لعدم وجود نقطة وصول صالحة.', 422);
        }

        $distanceMeters = $this->calculateDistanceMeters(
            $completionContext['latitude'],
            $completionContext['longitude'],
            (float) $endPoint->latitude,
            (float) $endPoint->longitude
        );

        if ($distanceMeters > self::COMPLETION_PROXIMITY_METERS) {
            throw ValidationException::withMessages([
                'latitude' => 'لا يمكن إنهاء الرحلة لأن السائق ليس قريباً بما يكفي من نقطة النهاية.',
            ]);
        }

        return $completionContext;
    }

    private function resolveAutoCompletionContext(Trip $trip): ?array
    {
        if (! $this->shouldAutoCompleteTrip($trip)) {
            return null;
        }

        $endPoint = $this->resolveTripEndPoint($trip);
        $snapshot = $this->resolveStoredTrackingLocation($trip);

        if ($endPoint && $snapshot !== null) {
            $distanceMeters = $this->calculateDistanceMeters(
                $snapshot['latitude'],
                $snapshot['longitude'],
                (float) $endPoint->latitude,
                (float) $endPoint->longitude
            );

            if ($distanceMeters <= self::COMPLETION_PROXIMITY_METERS) {
                return [
                    'source' => 'live_tracking',
                    'reason' => 'system_timeout_near_destination_live_tracking',
                    'latitude' => $snapshot['latitude'],
                    'longitude' => $snapshot['longitude'],
                ];
            }
        }

        if ($snapshot !== null && $trip->last_location_at && Carbon::parse($trip->last_location_at)->lte(now()->subMinutes(self::AUTO_COMPLETE_TRACKING_STALE_MINUTES))) {
            return [
                'source' => 'tracking_stale',
                'reason' => 'system_timeout_tracking_stale',
                'latitude' => $snapshot['latitude'],
                'longitude' => $snapshot['longitude'],
            ];
        }

        return [
            'source' => 'time_fallback',
            'reason' => 'system_timeout_no_tracking_time_fallback',
            'latitude' => $snapshot['latitude'] ?? null,
            'longitude' => $snapshot['longitude'] ?? null,
        ];
    }

    private function resolveDriverCompletionContext(Trip $trip, ?float $latitude, ?float $longitude): array
    {
        $snapshot = $this->resolveStoredTrackingLocation($trip);

        if ($snapshot !== null && $this->isTrackingSnapshotFresh($trip)) {
            return [
                'source' => 'live_tracking',
                'reason' => 'driver_near_destination_live_tracking',
                'latitude' => $snapshot['latitude'],
                'longitude' => $snapshot['longitude'],
            ];
        }

        if ($latitude !== null && $longitude !== null) {
            return [
                'source' => 'request_gps',
                'reason' => 'driver_near_destination_request_gps',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return [
            'source' => 'time_fallback',
            'reason' => 'driver_time_fallback',
            'latitude' => $snapshot['latitude'] ?? null,
            'longitude' => $snapshot['longitude'] ?? null,
        ];
    }

    private function resolveStoredTrackingLocation(Trip $trip): ?array
    {
        if ($trip->last_latitude === null || $trip->last_longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $trip->last_latitude,
            'longitude' => (float) $trip->last_longitude,
        ];
    }

    private function isTrackingSnapshotFresh(Trip $trip): bool
    {
        if (! $trip->last_location_at) {
            return false;
        }

        return Carbon::parse($trip->last_location_at)->gte(now()->subMinutes(self::LIVE_TRACKING_FRESHNESS_MINUTES));
    }

    private function resolveTripEndPoint(Trip $trip): mixed
    {
        $trip->loadMissing('points');

        return $trip->points
            ->sortByDesc('sequence_order')
            ->first(fn ($point) => $point->point_type === 'end')
            ?? $trip->points->sortByDesc('sequence_order')->first();
    }

    private function calculateDistanceMeters(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude
    ): float {
        $earthRadius = 6371000;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude))
            * cos(deg2rad($toLatitude))
            * sin($longitudeDelta / 2) ** 2;

        return 2 * $earthRadius * asin(min(1, sqrt($a)));
    }

    private function markTripBookingsAsCompleted(
        Trip $trip,
        BookingStatus $completedBookingStatus,
        ?User $actor,
        ?string $notes
    ): void {
        foreach ($trip->bookings as $booking) {
            $statusKey = $booking->status?->status_key;

            if (in_array($statusKey, ['canceled', 'rejected', 'completed'], true)) {
                continue;
            }

            $fromStatusId = $booking->status_id;

            $booking->forceFill([
                'status_id' => $completedBookingStatus->status_id,
                'completed_at' => now(),
            ])->save();

            BookingStatusLog::create([
                'booking_id' => $booking->booking_id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $completedBookingStatus->status_id,
                'changed_by' => $actor?->user_id,
                'reason' => $notes ?: 'تم إنهاء الرحلة وتحديث الحجز إلى منتهي.',
                'changed_at' => now(),
            ]);

            event(new BookingStatusChanged(
                $booking,
                $fromStatusId,
                $completedBookingStatus->status_id,
                $actor?->user_id,
                $notes
            ));

            $payment = $booking->payments->sortByDesc('payment_id')->first();
            if ($payment && $payment->payment_method === 'cash') {
                $payment->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                    'failure_reason' => null,
                ]);
            }
        }
    }

    private function deductCompletedTripCommission(
        Trip $trip,
        Wallet $wallet,
        User $driver,
        float $grossRevenue,
        float $commissionAmount,
        float $netRevenue
    ): void {
        $existingSettlement = WalletTransaction::query()
            ->where('wallet_id', $wallet->wallet_id)
            ->where('transaction_type', 'commission')
            ->whereHas('receipt', function (Builder $query) use ($trip) {
                $query->where('related_trip_id', $trip->trip_id)
                    ->where('receipt_type', 'driver_trip_settlement');
            })
            ->lockForUpdate()
            ->exists();

        if ($existingSettlement) {
            return;
        }

        $beforeBalance = (float) $wallet->balance;
        $afterBalance = round($beforeBalance - $commissionAmount, 2);

        $wallet->update(['balance' => $afterBalance]);

        $reference = $this->walletTransactionService->generateReference('WLT-COM');

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->wallet_id,
            'amount' => $commissionAmount,
            'transaction_type' => 'commission',
            'status' => 'completed',
            'transaction_reference' => $reference,
            'description' => 'خصم عمولة النظام على رحلة مكتملة.',
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'performed_by' => $driver->user_id,
        ]);

        $this->receiptService->createForTransaction($transaction, [
            'owner_user_id' => $driver->user_id,
            'wallet_id' => $wallet->wallet_id,
            'related_trip_id' => $trip->trip_id,
            'commission_rate_id' => $trip->commission_rate_id,
            'receipt_type' => 'driver_trip_settlement',
            'direction' => 'debit',
            'status' => 'paid',
            'amount' => $commissionAmount,
            'counterparty_name' => 'System',
            'reason' => 'خصم عمولة النظام على رحلة مكتملة.',
            'gross_amount' => $grossRevenue,
            'commission_percentage' => (float) $trip->commission_percentage,
            'commission_amount' => $commissionAmount,
            'net_amount' => $netRevenue,
            'metadata' => [
                'trip_id' => $trip->trip_id,
            ],
        ]);
    }

    private function notifyPassengersTripStarted(Trip $trip, User $actor): void
    {
        $passengerIds = $trip->bookings
            ->filter(fn (Booking $booking) => ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
            ->pluck('passenger_id')
            ->filter()
            ->unique()
            ->values();

        if ($passengerIds->isEmpty()) {
            return;
        }

        $notification = Notification::create([
            'title' => 'بدء الرحلة',
            'body' => "بدأت الرحلة رقم {$trip->trip_id} ويمكنك الآن متابعتها.",
            'notification_type' => 'trip_started',
            'reference_type' => 'trip',
            'reference_id' => $trip->trip_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_PASSENGER,
        ]);

        foreach ($passengerIds as $passengerId) {
            $this->notifications->sendExistingToUser($notification, (int) $passengerId, [
                'trip_id' => $trip->trip_id,
            ]);
        }
    }

    private function notifyTripCompleted(Trip $trip, ?User $actor, bool $autoCompleted): void
    {
        $passengerIds = $trip->bookings
            ->filter(fn (Booking $booking) => ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
            ->pluck('passenger_id')
            ->filter()
            ->unique()
            ->values();

        $passengerTitle = $autoCompleted ? 'تم إنهاء الرحلة تلقائياً' : 'تم إنهاء الرحلة';
        $passengerBody = $autoCompleted
            ? "تم إنهاء الرحلة رقم {$trip->trip_id} تلقائياً من قبل النظام، ويمكنك الآن تقييم الرحلة أو السائق."
            : "تم إنهاء الرحلة رقم {$trip->trip_id} بنجاح، ويمكنك الآن تقييم الرحلة أو السائق.";

        if ($passengerIds->isNotEmpty()) {
            $notification = Notification::create([
                'title' => $passengerTitle,
                'body' => $passengerBody,
                'notification_type' => $autoCompleted ? 'trip_auto_completed' : 'trip_completed',
                'reference_type' => 'trip',
                'reference_id' => $trip->trip_id,
                'created_by' => $actor?->user_id,
                'target_role' => Role::ROLE_PASSENGER,
            ]);

            foreach ($passengerIds as $passengerId) {
                $this->notifications->sendExistingToUser($notification, (int) $passengerId, [
                    'trip_id' => $trip->trip_id,
                ]);
            }
        }

        if ($trip->driver_id) {
            $driverNotification = Notification::create([
                'title' => $passengerTitle,
                'body' => $autoCompleted
                    ? "تم إنهاء الرحلة رقم {$trip->trip_id} تلقائياً من قبل النظام واحتساب المستحقات المالية."
                    : "تم إنهاء الرحلة رقم {$trip->trip_id} واحتساب المستحقات المالية.",
                'notification_type' => $autoCompleted ? 'driver_trip_auto_completed' : 'driver_trip_completed',
                'reference_type' => 'trip',
                'reference_id' => $trip->trip_id,
                'created_by' => $actor?->user_id,
                'target_role' => Role::ROLE_DRIVER,
            ]);

            $this->notifications->sendExistingToUser($driverNotification, $trip->driver_id, [
                'trip_id' => $trip->trip_id,
            ]);
        }
    }
}
