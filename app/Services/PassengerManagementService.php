<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Role;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
                'status',
            ])
            ->where('passenger_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();

        $completedTripStatuses = [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED];

        return [
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
                'number_of_complaints' => Complaint::query()
                    ->where('complainant_id', $user->user_id)
                    ->count(),
            ],
            'bookings' => $bookings->map(function (Booking $booking) {
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
            })->values(),
        ];
    }

    public function toggleStatus(int $id, User $actor): User
    {
        $user = $this->resolvePassenger($id);

        $oldStatus = $user->account_status;
        $newStatus = $oldStatus === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;

        $user->update(['account_status' => $newStatus]);

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action' => 'passenger.status_toggled',
            'entity_type' => User::class,
            'entity_id' => $user->user_id,
            'old_value' => ['account_status' => $oldStatus],
            'new_value' => ['account_status' => $newStatus],
            'description' => "Passenger {$user->full_name} (ID: {$user->user_id}) status changed from {$oldStatus} to {$newStatus} by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        return $user->fresh(['roles', 'wallet']);
    }

    private function resolvePassenger(int $id): User
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_PASSENGER))
            ->with(['roles', 'wallet'])
            ->find($id);

        if (! $user) {
            throw new RuntimeException('Ø§Ù„Ù…Ø³Ø§ÙØ± ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯.', 404);
        }

        return $user;
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
