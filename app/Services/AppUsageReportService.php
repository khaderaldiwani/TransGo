<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AppUsageReportService
{
    public function getAppUsageReport(array $filters): array
    {
        $fromDate = $filters['from_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $toDate = $filters['to_date'] ?? Carbon::now()->format('Y-m-d');
        $userType = $filters['user_type'] ?? null;
        $governorateId = $filters['governorate_id'] ?? null;
        $userRole = $filters['user_role'] ?? Role::ROLE_ADMIN;

        $activePassengerCount = null;
        $activeDriverCount = null;

        if (!$userType || $userType === Role::ROLE_PASSENGER) {
            $activePassengerCount = $this->countActivePassengers($fromDate, $toDate, $governorateId, $userRole);
        }

        if (!$userType || $userType === Role::ROLE_DRIVER) {
            $activeDriverCount = $this->countActiveDrivers($fromDate, $toDate, $governorateId, $userRole);
        }

        $totalActiveUsers = ($activePassengerCount ?? 0) + ($activeDriverCount ?? 0);

        // Calculate new users registered in the date range
        $newUsersCount = $this->countNewUsers($fromDate, $toDate, $userType, $governorateId, $userRole);

        // Calculate total completed bookings
        $totalBookingsCompleted = $this->countCompletedBookings($fromDate, $toDate, $governorateId, $userRole);

        // Calculate total users for percentage
        $totalUsers = $this->countTotalUsers($userType, $governorateId, $userRole);
        $activeUsersPercentage = $totalUsers > 0 ? round(($totalActiveUsers / $totalUsers) * 100, 2) : 0;

        return [
            'filters_applied' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'user_type' => $userType,
                'governorate_id' => $governorateId,
                'user_role' => $userRole,
            
            ],
            'summary' => [
                'active_users' => $totalActiveUsers,
                'new_users' => $newUsersCount,
                'total_bookings_completed' => $totalBookingsCompleted,
                'active_users_percentage' => $activeUsersPercentage,
            ],
        ];
    }

    private function countActivePassengers(string $fromDate, string $toDate, ?int $governorateId, string $userRole): int
    {
        $query = Booking::query()
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate);

        if ($governorateId) {
            $query->whereHas('trip', function ($query) use ($governorateId) {
                $query->where('start_governorate_id', $governorateId)
                    ->orWhere('end_governorate_id', $governorateId);
            });
        }

        // employee-specific governorate filtering removed

        return $query->distinct('passenger_id')->count('passenger_id');
    }

    private function countActiveDrivers(string $fromDate, string $toDate, ?int $governorateId, string $userRole): int
    {
        $query = Trip::query();

        $query->where(function ($query) use ($fromDate, $toDate) {
            $query->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)
                ->orWhere(function ($query) use ($fromDate, $toDate) {
                    $query->whereDate('actual_start_time', '>=', $fromDate)
                        ->whereDate('actual_start_time', '<=', $toDate);
                })
                ->orWhere(function ($query) use ($fromDate, $toDate) {
                    $query->whereDate('completed_at', '>=', $fromDate)
                        ->whereDate('completed_at', '<=', $toDate);
                })
                ->orWhere(function ($query) use ($fromDate, $toDate) {
                    $query->whereDate('tracking_started_at', '>=', $fromDate)
                        ->whereDate('tracking_started_at', '<=', $toDate);
                });
        });

        if ($governorateId) {
            $query->where(function ($query) use ($governorateId) {
                $query->where('start_governorate_id', $governorateId)
                    ->orWhere('end_governorate_id', $governorateId);
            });
        }

        // employee-specific governorate filtering removed

        return $query->distinct('driver_id')->count('driver_id');
    }

    

    private function countNewUsers(string $fromDate, string $toDate, ?string $userType, ?int $governorateId, string $userRole): int
    {
        $query = User::query()
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate);

        if ($userType) {
            $query->whereHas('roles', function ($q) use ($userType) {
                $q->where('name', $userType);
            });
        }

        if ($governorateId) {
            // For new users, we can't filter by governorate since they don't have trips yet
            // This might need adjustment based on business logic
        }

        // employee-specific governorate filtering removed

        return $query->count();
    }

    private function countCompletedBookings(string $fromDate, string $toDate, ?int $governorateId, string $userRole): int
    {
        $query = Booking::query()
            ->whereHas('trip', function ($q) {
                $q->whereHas('status', function ($statusQ) {
                    $statusQ->whereIn('status_key', ['completed', 'auto_completed']);
                });
            })
            ->whereDate('updated_at', '>=', $fromDate)
            ->whereDate('updated_at', '<=', $toDate);

        if ($governorateId) {
            $query->whereHas('trip', function ($q) use ($governorateId) {
                $q->where('start_governorate_id', $governorateId)
                    ->orWhere('end_governorate_id', $governorateId);
            });
        }

        // employee-specific governorate filtering removed

        return $query->count();
    }

    private function countTotalUsers(?string $userType, ?int $governorateId, string $userRole): int
    {
        $query = User::query();

        if ($userType) {
            $query->whereHas('roles', function ($q) use ($userType) {
                $q->where('name', $userType);
            });
        }

        if ($governorateId) {
            // For total users, filter by users who have trips in the governorate
            $query->whereHas('trips', function ($q) use ($governorateId) {
                $q->where('start_governorate_id', $governorateId)
                    ->orWhere('end_governorate_id', $governorateId);
            });
        }

        // employee-specific governorate filtering removed

        return $query->count();
    }
}
