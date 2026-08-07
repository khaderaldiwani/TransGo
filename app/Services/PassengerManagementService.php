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
use Illuminate\Http\UploadedFile;
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

    public function getOwnProfile(User $user): array
    {
        return $this->buildPassengerProfile($user, true, true);
    }

    public function updateProfile(User $user, array $data): array
    {
        if (! empty($data['name'])) {
            $user->full_name = $data['name'];
        }

        if (! empty($data['photo'])) {
            $user->profile_photo = $this->toPublicStoragePath($this->storeProfilePhoto($data['photo']));
        }

        $user->save();

        return $this->buildPassengerProfile($user, true, true);
    }

    public function getOtherPassengerProfile(int $id, bool $includeEmail = false, bool $includePhone = false): array
    {
        $user = $this->resolvePassenger($id);

        return $this->buildPassengerProfile($user, $includeEmail, $includePhone);
    }

    public function getPassenger(int $id): array
    {
        $user = $this->resolvePassenger($id);

        // Build response matching admin passenger detail contract
        return [
            'user_id' => $user->user_id,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'must_change_password' => (bool) $user->must_change_password,
            'account_status' => $user->account_status,
            'rating' => $user->rating !== null ? number_format((float) $user->rating, 2, '.', '') : number_format(0, 2, '.', ''),
            'rating_last_updated' => $user->rating_last_updated ? $user->rating_last_updated->toIso8601String() : null,
            'created_by' => $user->created_by,
            'registration_type' => $user->registration_type,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'roles' => $user->roles->map(function ($role) use ($user) {
                return [
                    'id' => $role->id ?? $role->role_id ?? null,
                    'name' => $role->name,
                    'name_display' => $this->roleNameDisplay($role->name),
                    'created_at' => $role->created_at?->toIso8601String(),
                    'updated_at' => $role->updated_at?->toIso8601String(),
                    'pivot' => [
                        'user_id' => $user->user_id,
                        'role_id' => $role->id ?? $role->role_id ?? null,
                    ],
                ];
            })->toArray(),
            'wallet' => $user->wallet ? $user->wallet->toArray() : null,
        ];
    }

    private function buildPassengerProfile(User $user, bool $includeEmail = false, bool $includePhone = false): array
    {
        $counts = $this->buildPassengerReservationCounts($user);

        return [
            'photo' => $this->absoluteFileUrl($user->profile_photo),
            'name' => $user->full_name,
            'email' => $includeEmail ? $user->email : null,
            'phone_number' => $includePhone ? $user->phone : null,
            'cancelled_reservations_count' => $counts['cancelled'],
            'completed_reservations_count' => $counts['completed'],
            'rating' => $this->buildPassengerRating($user),
        ];
    }

    private function roleNameDisplay(string $roleName): string
    {
        $language = request()->getPreferredLanguage(['ar', 'en']) ?? 'en';

        return match ($language) {
            'ar' => match ($roleName) {
                Role::ROLE_PASSENGER => 'راكب',
                default => $roleName,
            },
            default => match ($roleName) {
                Role::ROLE_PASSENGER => 'Passenger',
                default => $roleName,
            },
        };
    }

    private function buildPassengerReservationCounts(User $user): array
    {
        $base = Booking::query()->where('passenger_id', $user->user_id);

        $completed = (clone $base)
            ->where(function ($query) {
                $query->whereHas('status', fn ($q) => $q->where('status_key', 'completed'))
                    ->orWhereHas('trip.status', fn ($q) => $q->whereIn('status_key', [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED]));
            })
            ->count();

        $cancelled = (clone $base)
            ->where(function ($query) {
                $query->whereHas('status', fn ($q) => $q->whereIn('status_key', ['canceled', 'rejected']))
                    ->orWhereHas('trip.status', fn ($q) => $q->where('status_key', TripStatus::CANCELED));
            })
            ->count();

        return [
            'completed' => $completed,
            'cancelled' => $cancelled,
        ];
    }

    private function buildPassengerRating(User $user): float
    {
        $rating = DriverReview::query()
            ->where('passenger_id', $user->user_id)
            ->where('rated_user_type', Role::ROLE_PASSENGER)
            ->where('is_visible', true)
            ->avg('rating');

        return $rating !== null ? round((float) $rating, 2) : 0.0;
    }

    private function storeProfilePhoto(UploadedFile $photo): string
    {
        return $photo->store('passengers/profile-photos', 'public');
    }

    private function toPublicStoragePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return 'storage/'.$path;
    }

    private function absoluteFileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return url('/'.ltrim($path, '/'));
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
