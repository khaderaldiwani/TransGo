<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\Notification;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminBookingManagementService
{
    public function listBookings(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $query = Trip::query()
            ->has('bookings')
            ->with([
                'driver.user',
                'status',
                'startGovernorate',
                'endGovernorate',
                'bookings.passenger',
                'bookings.status',
                'bookings.pickupPoint.governorate',
                'bookings.pickupPoint.tripPoint',
                'bookings.payments',
                'bookings.review',
            ]);

        if ($filters['trip_id'] !== null) {
            $query->where('trips.trip_id', $filters['trip_id']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($search) {
                $query->whereHas('driver.user', function (Builder $driverQuery) use ($search) {
                    $driverQuery->where('full_name', 'like', "%{$search}%");
                })->orWhereHas('bookings', function (Builder $bookingQuery) use ($search) {
                    $bookingQuery->where('booking_code', 'like', "%{$search}%")
                        ->orWhereHas('passenger', function (Builder $passengerQuery) use ($search) {
                            $passengerQuery->where('full_name', 'like', "%{$search}%");
                        });
                });
            });
        }

        // Apply booking filters to determine which trips to show
        $hasBookingFilters = $filters['status'] !== '' 
            || $filters['payment_method'] !== '' 
            || $filters['from_date'] !== '' 
            || $filters['to_date'] !== '';
        
        if ($hasBookingFilters) {
            $query->whereHas('bookings', function (Builder $bookingQuery) use ($filters) {
                $this->applyBookingFilters($bookingQuery, $filters);
            });
        }

        $paginator = $query->orderBy('departure_time')->paginate($filters['per_page']);

        $items = collect();
        foreach ($paginator->getCollection() as $trip) {
            $bookings = $trip->bookings;
            
            // Filter bookings for display
            if ($hasBookingFilters) {
                $tempQuery = Booking::query();
                $this->applyBookingFilters($tempQuery, $filters);
                $bookingIds = $tempQuery->pluck('booking_id')->toArray();
                $bookings = $bookings->whereIn('booking_id', $bookingIds);
            }
            
            // Apply search filter to bookings
            if ($filters['search'] !== '') {
                $search = $filters['search'];
                $driverMatchesSearch = $this->containsIgnoreCase($trip->driver?->user?->full_name, $search);
                
                if (!$driverMatchesSearch) {
                    $bookings = $bookings->filter(function ($booking) use ($search) {
                        return $this->containsIgnoreCase($booking->booking_code, $search)
                            || $this->containsIgnoreCase($booking->passenger?->full_name, $search);
                    });
                }
            }
            
            $items->push($this->transformTripWithBookings($trip, $bookings));
        }

        return [
            'filters' => $filters,
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'trip_count' => $paginator->total(),
                'booking_count' => $items->sum('bookings_count'),
            ],
            'items' => $items->values(),
        ];
    }
private function applyBookingFilters(Builder $query, array $filters): Builder
{
    if ($filters['status'] !== '') {  // ✅ فلترة حسب حالة الحجز
        $statusId = BookingStatus::query()
            ->where('status_key', $filters['status'])
            ->value('status_id');
        
        if ($statusId) {
            $query->where('status_id', $statusId);
        }
    }

    if ($filters['payment_method'] !== '') {  // ✅ فلترة حسب طريقة الدفع
        $query->where('payment_method', $filters['payment_method']);
    }

    if ($filters['from_date'] !== '') {  // ✅ فلترة حسب تاريخ الحجز (من)
        $query->whereDate('created_at', '>=', $filters['from_date']);
    }

    if ($filters['to_date'] !== '') {  // ✅ فلترة حسب تاريخ الحجز (إلى)
        $query->whereDate('created_at', '<=', $filters['to_date']);
    }

    return $query;
}

private function transformTripWithBookings(Trip $trip, Collection $bookings): array
{
    return [
        'trip_id' => $trip->trip_id,
        'departure_time' => $trip->departure_time?->toIso8601String(),
        'driver_name' => $trip->driver?->user?->full_name, // ✅ اسم السائق
        'from' => $trip->startGovernorate?->name,
        'to' => $trip->endGovernorate?->name,
        'bookings_count' => $bookings->count(),
        'bookings' => $bookings->map(function ($booking) {
            $payment = $booking->payments?->sortByDesc('payment_id')->first();
            
            return [
                'booking_id' => $booking->booking_id,
                'passenger_name' => $booking->passenger?->full_name, // ✅ اسم الراكب
                'passenger_image' => $booking->passenger?->profile_photo,
                'passenger_rating' => $booking->passenger?->rating !== null ? (float) $booking->passenger->rating : null,
                'seats_reserved' => (int) ($booking->seats_reserved ?? 0), // ✅ عدد المقاعد المطلوبة
                'payment_method' => $booking->payment_method, // ✅ طريقة الدفع
                'booking_details_url' => "/api/bookings/{$booking->booking_id}", // ✅ زر عرض تفاصيل الحجز
                'status' => $booking->status?->status_name,
                'total_amount' => (float) ($booking->total_amount ?? 0),
                'created_at' => $booking->created_at?->toIso8601String(),
            ];
        })->values(),
    ];
}

    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'payment_method' => trim((string) ($filters['payment_method'] ?? '')),
            'from_date' => trim((string) ($filters['from_date'] ?? '')),
            'to_date' => trim((string) ($filters['to_date'] ?? '')),
            'trip_id' => isset($filters['trip_id']) && $filters['trip_id'] !== null && $filters['trip_id'] !== '' ? (int) $filters['trip_id'] : null,
            'per_page' => max(1, min(100, (int) ($filters['per_page'] ?? 15))),
        ];
    }

    private function containsIgnoreCase(?string $haystack, string $needle): bool
    {
        if ($haystack === null || $needle === '') {
            return false;
        }
        return mb_stripos($haystack, $needle) !== false;
    }
     /**
     * Get detailed information for a specific booking
     */
    public function getBookingDetails(int $bookingId): array
    {
        // Load the booking with all necessary relationships
        $booking = Booking::query()
            ->with([
                'passenger',
                'trip.driver.user',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'status',
                'pickupPoint.governorate',
                'pickupPoint.tripPoint',
                'payments',
                'review',
            ])
            ->findOrFail($bookingId);

        // Get attendance status (you may need to add this field to your bookings table)
        $attendanceStatus = $this->getAttendanceStatus($booking);
        
        // Get rejection/cancellation reason
        $reason = $this->getCancellationReason($booking);

        return [
            // Passenger Information
            'passenger_info' => [
                'full_name' => $booking->passenger?->full_name,
                'phone' => $booking->passenger?->phone,
                'image' => $booking->passenger?->profile_photo,
                'rating' => $booking->passenger?->rating !== null ? (float) $booking->passenger->rating : null,
                'seats_reserved' => (int) ($booking->seats_reserved ?? 0),
                'attendance_status' => $attendanceStatus, // 'not_recorded', 'present', 'absent'
            ],
            
            // Booking Information
            'booking_info' => [
                'booking_id' => $booking->booking_id,
                'booking_code' => $booking->booking_code,
                'created_at' => $booking->created_at?->toIso8601String(),
                'booking_type' => $booking->booking_type, // 'shared' or 'private'
                'total_amount' => (float) ($booking->total_amount ?? 0),
                'status' => [
                    'key' => $booking->status?->status_key,
                    'name' => $booking->status?->status_name,
                ],
                'rejection_cancellation_reason' => $reason, // سبب الرفض أو الإلغاء
                'payment_method' => $booking->payment_method, // 'cash' or 'online'
                'payment_status' => $this->getPaymentStatus($booking),
            ],
            
            // Pickup Point Information
            'pickup_point_info' => [
                'point_name' => $booking->pickupPoint?->point_name,
                'governorate' => $booking->pickupPoint?->governorate?->name,
                'area' => $booking->pickupPoint?->area, // إذا كان موجوداً
                'address' => $booking->pickupPoint?->address,
                'location_coordinates' => [
                    'lat' => $booking->pickupPoint?->latitude, // افتراض وجود هذا الحقل
                    'lng' => $booking->pickupPoint?->longitude, // افتراض وجود هذا الحقل
                ],
                'meeting_time' => $booking->pickupPoint?->meeting_time?->toIso8601String(),
                'point_status' => $this->getPickupPointStatus($booking->pickupPoint), // 'new' or 'existing'
            ],
            
            // Trip Information (context)
            'trip_info' => [
                'trip_id' => $booking->trip?->trip_id,
                'departure_time' => $booking->trip?->departure_time?->toIso8601String(),
                'from' => $booking->trip?->startGovernorate?->name,
                'to' => $booking->trip?->endGovernorate?->name,
                'driver_name' => $booking->trip?->driver?->user?->full_name,
                'driver_phone' => $booking->trip?->driver?->user?->phone,
            ],
        ];
    }

    public function updateBookingStatus(int $bookingId, string $newStatus, ?string $reason = null, ?User $actor = null): array
    {
        $allowedStatuses = ['accepted', 'rejected'];
        $immutableStatuses = ['canceled', 'completed'];

        $booking = Booking::query()
            ->with(['status', 'passenger', 'trip.driver.user', 'pickupPoint', 'payments'])
            ->findOrFail($bookingId);

        $currentStatusKey = $booking->status?->status_key;
        if (in_array($currentStatusKey, $immutableStatuses, true)) {
            throw new RuntimeException('لا يمكن تعديل حالة الحجز بعد أن يصبح ملغى أو منتهي.', 422);
        }

        if (! in_array($newStatus, $allowedStatuses, true)) {
            throw new RuntimeException('يمكن تغيير الحالة فقط إلى مقبول أو مرفوض.', 422);
        }

        if ($newStatus === 'rejected' && trim((string) $reason) === '') {
            throw new RuntimeException('يجب تقديم سبب الرفض عند تغيير الحالة إلى مرفوض.', 422);
        }

        $newStatusModel = BookingStatus::query()
            ->where('status_key', $newStatus)
            ->first();

        if (! $newStatusModel) {
            throw new RuntimeException('حالة الحجز الجديدة غير معتمدة.', 422);
        }

        $oldStatusId = $booking->status?->status_id;

        DB::transaction(function () use ($booking, $newStatusModel, $oldStatusId, $currentStatusKey, $newStatus, $reason, $actor) {
            $booking->status_id = $newStatusModel->status_id;

            if ($newStatus === 'accepted') {
                $booking->confirmed_at = now();
            }

            if ($newStatus === 'rejected') {
                $booking->rejected_at = now();
            }

            $booking->save();

            BookingStatusLog::create([
                'booking_id' => $booking->booking_id,
                'from_status_id' => $oldStatusId,
                'to_status_id' => $newStatusModel->status_id,
                'changed_by' => $actor?->user_id,
                'reason' => $reason,
                'changed_at' => now(),
            ]);

            $this->sendBookingStatusNotification($booking, $currentStatusKey, $newStatus, $actor);
        });

        return $this->getBookingDetails($bookingId);
    }

    private function sendBookingStatusNotification(Booking $booking, string $oldStatus, string $newStatus, ?User $actor = null): void
    {
        $message = match ($newStatus) {
            'accepted' => 'تم قبول حجزك بنجاح.',
            'rejected' => 'تم رفض حجزك.',
            default => 'تم تحديث حالة حجزك.',
        };

        $notification = Notification::create([
            'title' => 'تحديث حالة الحجز',
            'body' => $message,
            'notification_type' => 'booking_status_update',
            'reference_type' => 'booking',
            'reference_id' => $booking->booking_id,
            'created_by' => $actor?->user_id,
            'target_role' => 'passenger',
        ]);

        if ($booking->passenger?->user_id) {
            UserNotification::firstOrCreate([
                'notification_id' => $notification->notification_id,
                'user_id' => $booking->passenger->user_id,
            ], [
                'is_sent' => true,
                'sent_at' => now(),
            ]);
        }
    }

    /**
     * Get attendance status for the passenger
     */
    private function getAttendanceStatus(Booking $booking): string
    {
        // You need to add an 'attendance_status' column to your bookings table
        // Values: 'not_recorded', 'present', 'absent'
        return $booking->attendance_status ?? 'not_recorded';
    }

    /**
     * Get rejection or cancellation reason
     */
    private function getCancellationReason(Booking $booking): ?string
    {
        if (! empty($booking->cancellation?->reason)) {
            return $booking->cancellation->reason;
        }

        if (! empty($booking->cancellation_reason)) {
            return $booking->cancellation_reason;
        }

        $statusKey = $booking->status?->status_key;
        if (in_array($statusKey, ['rejected', 'canceled'], true)) {
            $reason = BookingStatusLog::query()
                ->where('booking_id', $booking->booking_id)
                ->whereNotNull('reason')
                ->orderByDesc('changed_at')
                ->value('reason');

            return $reason ?: 'لم يتم تقديم سبب';
        }

        return null;
    }

    /**
     * Get payment status
     */
    private function getPaymentStatus(Booking $booking): string
    {
        $payment = $booking->payments?->sortByDesc('payment_id')->first();
        
        if ($payment && $payment->payment_status) {
            return $payment->payment_status; // 'pending', 'completed', 'failed'
        }
        
        return 'pending';
    }

    /**
     * Determine if pickup point is new or existing
     */
    private function getPickupPointStatus($pickupPoint): string
    {
        if (!$pickupPoint) {
            return 'not_specified';
        }
        
        // You can determine this based on creation date or a specific field
        // For example, if the pickup point was created recently
        if ($pickupPoint->created_at && $pickupPoint->created_at->diffInDays(now()) < 1) {
            return 'new';
        }
        
        return 'existing';
    }
}
