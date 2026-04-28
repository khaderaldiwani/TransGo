<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\DriverReview;
use App\Models\Role;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class PassengerManagementService
{
    public function listPassengers(array $filters): LengthAwarePaginator
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_PASSENGER))
            ->with(['roles', 'wallet']);

        if (! empty($filters['name'])) {
            $query->where('full_name', 'like', "%{$filters['name']}%");
        }

        if (! empty($filters['phone'])) {
            $query->where('phone', 'like', "%{$filters['phone']}%");
        }

        if (! empty($filters['email'])) {
            $query->where('email', 'like', "%{$filters['email']}%");
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $q->orWhere('user_id', (int) $search);
                }
            });
        }

        if (isset($filters['account_status']) && $filters['account_status'] !== '') {
            $query->where('account_status', $filters['account_status']);
        }

        $sortBy = in_array($filters['sort_by'] ?? '', ['full_name', 'email', 'created_at', 'account_status'])
            ? $filters['sort_by']
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortOrder)
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getPassenger(int $id): array
    {
        $user = $this->resolvePassenger($id);

        $bookings = Booking::query()
            ->with([
                'trip.status',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'trip.points',
                'trip.driver.user',
                'status',
            ])
            ->where('passenger_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();

        $complaints = Complaint::query()
            ->with(['statusLogs.actor'])
            ->where('complainant_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();

        $adminActions = AuditLog::query()
            ->with('actor')
            ->where('entity_type', User::class)
            ->where('entity_id', $user->user_id)
            ->where(function ($query) {
                $query->where('action', 'like', 'passenger.%')
                    ->orWhere('action', 'like', 'user.status_%');
            })
            ->orderByDesc('created_at')
            ->get();

        $passengerRating = DriverReview::query()
            ->where('passenger_id', $user->user_id)
            ->where('rated_user_type', 'passenger')
            ->selectRaw('COUNT(*) as total, AVG(rating) as avg')
            ->first();

        $averageRating = $passengerRating && (int) $passengerRating->total > 0
            ? round((float) $passengerRating->avg, 2)
            : (float) $user->rating;

        $bookingRows = $bookings->map(fn (Booking $booking) => $this->transformPassengerBookingRow($booking))->values();
        $bookingCounts = $this->buildBookingCounts($bookingRows);

        $complaintRows = $complaints->map(fn (Complaint $complaint) => $this->transformComplaintRow($complaint))->values();
        $openComplaintsCount = $complaintRows->filter(
            fn (array $row) => in_array($row['status'], ['pending', 'under_review'], true)
        )->count();
        $resolvedComplaintsCount = $complaintRows->where('status', 'resolved')->count();

        $adminActionRows = $adminActions->map(fn (AuditLog $log) => $this->transformAdminActionRow($log))->values();

        $completedTripStatuses = [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED];

        $legacyBookings = $bookings->map(function (Booking $booking) {
            $durationMinutes = (int) ($booking->trip?->estimated_duration_minutes ?? 0);

            return [
                'booking_id' => $booking->booking_id,
                'trip_id' => $booking->trip_id,
                'date' => $booking->trip?->departure_time?->toIso8601String() ?? $booking->created_at?->toIso8601String(),
                'period' => [
                    'minutes' => $durationMinutes,
                    'text' => $this->formatDuration($durationMinutes),
                ],
                'type' => $booking->booking_type,
                'status' => [
                    'key' => $booking->status?->status_key,
                    'name' => $booking->status?->status_name,
                ],
                'payment_method' => $booking->payment_method,
                'route' => [
                    'from' => $booking->trip?->startGovernorate?->name,
                    'to' => $booking->trip?->endGovernorate?->name,
                ],
            ];
        })->values();

        return [
            'basic_information' => [
                'full_name' => $user->full_name,
                'mobile_number' => $user->phone,
                'account_status' => $this->resolvePassengerStatus((int) $user->account_status),
                'created_at' => $user->created_at?->toIso8601String(),
                'average_rating' => $averageRating,
                'completed_trips_count' => $bookings
                    ->filter(fn (Booking $booking) => in_array($booking->trip?->status?->status_key, $completedTripStatuses, true))
                    ->pluck('trip_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'cancelled_trips_count' => $bookings
                    ->filter(fn (Booking $booking) => $booking->trip?->status?->status_key === TripStatus::CANCELED)
                    ->pluck('trip_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'profile_photo' => null,
                'email' => $user->email,
            ],
            'bookings_history' => [
                'bookings' => $bookingRows,
                ...$bookingCounts,
            ],
            'complaints' => [
                'items' => $complaintRows,
                'total_complaints_count' => $complaintRows->count(),
                'open_complaints_count' => $openComplaintsCount,
                'resolved_complaints_count' => $resolvedComplaintsCount,
            ],
            'admin_action_log' => [
                'actions' => $adminActionRows,
            ],

            // Backward compatibility for existing clients/tests.
            'account_details' => [
                'id' => $user->user_id,
                'name' => $user->full_name,
                'phone' => $user->phone,
                'email' => $user->email,
                'wallet_amount' => $user->wallet?->balance !== null ? (float) $user->wallet->balance : 0.0,
                'completed_trips_count' => $bookings
                    ->filter(fn (Booking $booking) => in_array($booking->trip?->status?->status_key, $completedTripStatuses, true))
                    ->pluck('trip_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'completed_bookings_count' => $bookings
                    ->filter(fn (Booking $booking) => $booking->status?->status_key === 'completed')
                    ->count(),
                'cancelled_trips_count' => $bookings
                    ->filter(fn (Booking $booking) => $booking->trip?->status?->status_key === TripStatus::CANCELED)
                    ->pluck('trip_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'cancelled_bookings_count' => $bookings
                    ->filter(fn (Booking $booking) => in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
                    ->count(),
            ],
            'account_info' => [
                'status' => [
                    'value' => $user->account_status,
                    'text' => $user->account_status_text,
                ],
                'registration_method' => $user->registration_type,
                'registration_method_text' => $user->registration_type_text,
                'created_at' => $user->created_at?->toIso8601String(),
                'number_of_complaints' => $complaintRows->count(),
            ],
            'bookings' => $legacyBookings,
        ];
    }

    public function toggleStatus(int $id, User $actor): User
    {
        $user = $this->resolvePassenger($id);

        $oldStatus = $user->account_status;
        $newStatus = $oldStatus === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;

        $oldStatusText = $this->resolvePassengerStatus((int) $oldStatus);
        $newStatusText = $this->resolvePassengerStatus((int) $newStatus);
        $actionType = $newStatus === User::STATUS_ACTIVE ? 'activate' : 'suspend';

        $user->update(['account_status' => $newStatus]);

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action' => 'passenger.status_toggled',
            'entity_type' => User::class,
            'entity_id' => $user->user_id,
            'old_value' => [
                'account_status' => $oldStatus,
                'account_status_text' => $oldStatusText,
            ],
            'new_value' => [
                'account_status' => $newStatus,
                'account_status_text' => $newStatusText,
                'action_type' => $actionType,
            ],
            'description' => "Passenger {$user->full_name} (ID: {$user->user_id}) status changed from {$oldStatusText} to {$newStatusText} by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        return $user->fresh(['roles', 'wallet']);
    }

    private function resolvePassenger(int $id): User
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_PASSENGER))
            ->with(['roles', 'wallet'])
            ->find($id);

        if (! $user) {
            throw new RuntimeException('Passenger not found.', 404);
        }

        return $user;
    }

    private function transformPassengerBookingRow(Booking $booking): array
    {
        $trip = $booking->trip;
        $startPoint = $trip?->points?->firstWhere('point_type', 'start') ?? $trip?->points?->sortBy('sequence_order')?->first();
        $endPoint = $trip?->points?->firstWhere('point_type', 'end') ?? $trip?->points?->sortByDesc('sequence_order')?->first();

        return [
            'booking_id' => $booking->booking_id,
            'trip_id' => $booking->trip_id,
            'trip_datetime' => $trip?->departure_time?->toIso8601String(),
            'trip_type' => $booking->booking_type,
            'booking_status' => $this->normalizeBookingStatus($booking),
            'payment_method' => $booking->payment_method,
            'from_location' => $startPoint?->address ?? $trip?->startGovernorate?->name,
            'to_location' => $endPoint?->address ?? $trip?->endGovernorate?->name,
            'trip_price' => (float) ($booking->total_amount ?? 0),
            'driver_name' => $trip?->driver?->user?->full_name,
            'driver_phone' => $trip?->driver?->user?->phone,
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }

    private function buildBookingCounts(Collection $bookingRows): array
    {
        return [
            'total_bookings_count' => $bookingRows->count(),
            'completed_bookings_count' => $bookingRows->where('booking_status', 'completed')->count(),
            'cancelled_bookings_count' => $bookingRows->where('booking_status', 'cancelled')->count(),
            'rejected_bookings_count' => $bookingRows->where('booking_status', 'rejected')->count(),
            'pending_bookings_count' => $bookingRows->where('booking_status', 'pending')->count(),
        ];
    }

    private function normalizeBookingStatus(Booking $booking): string
    {
        $statusKey = $booking->status?->status_key;

        if ($statusKey === 'accepted') {
            return 'confirmed';
        }

        if ($statusKey === 'canceled') {
            return 'cancelled';
        }

        if ($statusKey === 'rejected') {
            return 'rejected';
        }

        if ($statusKey === 'completed') {
            return 'completed';
        }

        if ($statusKey === 'pending' && $booking->trip?->departure_time && $booking->trip->departure_time->isPast()) {
            return 'expired';
        }

        return 'pending';
    }

    private function transformComplaintRow(Complaint $complaint): array
    {
        $targetType = 'system';
        $targetId = null;

        if ($complaint->related_driver_id) {
            $targetType = 'driver';
            $targetId = $complaint->related_driver_id;
        } elseif ($complaint->related_trip_id) {
            $targetType = 'trip';
            $targetId = $complaint->related_trip_id;
        }

        $latestAdminLog = $complaint->statusLogs
            ->whereNotNull('notes')
            ->sortByDesc('changed_at')
            ->first();

        return [
            'complaint_id' => $complaint->complaint_id,
            'subject' => Str::title(str_replace('_', ' ', $complaint->complaint_type)),
            'message' => $complaint->description,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => $this->normalizeComplaintStatus($complaint->status),
            'admin_response' => $latestAdminLog?->notes,
            'created_at' => $complaint->created_at?->toIso8601String(),
            'resolved_at' => $complaint->resolved_at?->toIso8601String(),
        ];
    }

    private function normalizeComplaintStatus(string $status): string
    {
        return match ($status) {
            'new' => 'pending',
            'in_progress' => 'under_review',
            'completed' => 'resolved',
            'dismissed' => 'dismissed',
            default => 'pending',
        };
    }

    private function transformAdminActionRow(AuditLog $log): array
    {
        $previousStatus = $this->normalizeStatusLabel(
            data_get($log->old_value, 'account_status_text') ?? data_get($log->old_value, 'account_status')
        );
        $newStatus = $this->normalizeStatusLabel(
            data_get($log->new_value, 'account_status_text') ?? data_get($log->new_value, 'account_status')
        );

        $actionType = (string) (data_get($log->new_value, 'action_type') ?: $this->inferActionType($previousStatus, $newStatus, $log->action));

        return [
            'action_id' => $log->id,
            'action_type' => $actionType,
            'reason' => data_get($log->new_value, 'reason'),
            'admin_name' => $log->actor?->full_name,
            'admin_id' => $log->actor?->user_id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'notes' => $log->description,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function inferActionType(?string $previousStatus, ?string $newStatus, string $action): string
    {
        if ($newStatus === 'active') {
            return $previousStatus === 'banned' ? 'restore' : 'activate';
        }

        if ($newStatus === 'suspended') {
            return 'suspend';
        }

        if ($newStatus === 'banned') {
            return 'ban';
        }

        if (Str::contains($action, 'warn')) {
            return 'warn';
        }

        return 'restore';
    }

    private function normalizeStatusLabel(mixed $status): ?string
    {
        if ($status === null) {
            return null;
        }

        if (is_numeric($status)) {
            return ((int) $status) === User::STATUS_ACTIVE ? 'active' : 'suspended';
        }

        $normalized = Str::lower((string) $status);

        return match ($normalized) {
            '1', 'active' => 'active',
            '0', 'inactive', 'suspended' => 'suspended',
            'banned' => 'banned',
            'pending' => 'pending',
            default => $normalized,
        };
    }

    private function resolvePassengerStatus(int $rawStatus): string
    {
        return $rawStatus === User::STATUS_ACTIVE ? 'active' : 'suspended';
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 minutes';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return $remainingMinutes.' minutes';
        }

        if ($remainingMinutes === 0) {
            return $hours.' hour'.($hours > 1 ? 's' : '');
        }

        return $hours.' hour'.($hours > 1 ? 's' : '').' '.$remainingMinutes.' minutes';
    }
}
