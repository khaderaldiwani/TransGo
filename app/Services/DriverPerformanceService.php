<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCancellation;
use App\Models\BookingStatus;
use App\Models\DriverReview;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use App\Models\Governorate;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DriverPerformanceService
{
    public function getDriverPerformanceReport(array $filters): array
    {
        $driverId = $filters['driver_id'] ?? null;
        $governorateId = $filters['governorate_id'] ?? null;
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $driverGovernorates = $filters['driver_governorates'] ?? null;
        $userRole = $filters['user_role'] ?? Role::ROLE_ADMIN;

        $drivers = $this->resolveDrivers($driverId, $userRole, $driverGovernorates);

        if ($driverId && $drivers->isEmpty()) {
            throw new RuntimeException('Driver not found', 404);
        }

        $driverReports = $drivers->map(function (User $driver) use ($fromDate, $toDate, $governorateId) {
            return $this->buildDriverReport($driver, $fromDate, $toDate, $governorateId);
        })->toArray();

        return [
            'filters_applied' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'driver_id' => $driverId,
                'governorate_id' => $governorateId,
            ],
            'driver_reports' => $driverReports,
        ];
    }

    private function resolveDrivers(?string $driverId, string $userRole, ?string $driverGovernorates)
    {
        if ($driverId) {
            $driver = $this->findDriver($driverId);
            if (!$driver) {
                return collect();
            }

            if ($userRole === Role::ROLE_EMPLOYEE) {
                $this->validateEmployeeAccess($driver, $driverGovernorates);
            }

            return collect([$driver]);
        }

        $query = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_DRIVER));

        if ($userRole === Role::ROLE_EMPLOYEE) {
            if (!$driverGovernorates) {
                throw new RuntimeException('Employee access requires driver_governorates parameter', 403);
            }

            $allowedIds = array_filter(array_map('trim', explode(',', $driverGovernorates)));
            $query->whereHas('trips', function ($q) use ($allowedIds) {
                $q->whereIn('start_governorate_id', $allowedIds)
                  ->orWhereIn('end_governorate_id', $allowedIds);
            });
        }

        return $query->get();
    }

    private function buildDriverReport(User $driver, ?string $fromDate, ?string $toDate, ?int $governorateId): array
    {
        $tripsQuery = Trip::where('driver_id', $driver->user_id);

        if ($fromDate) {
            $tripsQuery->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $tripsQuery->whereDate('created_at', '<=', $toDate);
        }

        if ($governorateId) {
            $tripsQuery->where(function ($q) use ($governorateId) {
                $q->where('start_governorate_id', $governorateId)
                  ->orWhere('end_governorate_id', $governorateId);
            });
        }

        $trips = $tripsQuery->with(['bookings', 'status'])->get();

        $tripIds = $trips->pluck('trip_id');
        $bookings = $tripIds->isEmpty()
            ? collect()
            : Booking::whereIn('trip_id', $tripIds)
                ->with(['status', 'passenger', 'cancellation'])
                ->get();

        $summary = $this->calculateSummary($trips, $bookings, $driver->rating ?? 0);
        $rideBreakdown = $this->getRideBreakdown($bookings);
        $driverGovernorate = $this->getDriverGovernorate($trips);

        return [
            'driver_info' => [
                'id' => $driver->user_id,
                'name' => $driver->full_name,
                'driver_number' => 'DRV' . $driver->user_id,
                'governorate' => $driverGovernorate,
                'current_rating' => $driver->rating ?? 0,
            ],
            'summary' => $summary,
            'ride_breakdown' => $rideBreakdown,
        ];
    }

    private function findDriver(string $driverId): ?User
    {
        // Try to find by user_id first
        $driver = User::where('user_id', $driverId)->first();
        if ($driver && $driver->roles()->where('name', Role::ROLE_DRIVER)->exists()) {
            return $driver;
        }

        // Try to find by phone if driverId looks like a phone
        if (preg_match('/^\d{10}$/', $driverId)) {
            $driver = User::where('phone', $driverId)->first();
            if ($driver && $driver->roles()->where('name', Role::ROLE_DRIVER)->exists()) {
                return $driver;
            }
        }

        return null;
    }

    private function validateEmployeeAccess(User $driver, ?string $allowedGovernorates): void
    {
        if (!$allowedGovernorates) {
            throw new RuntimeException('Employee access requires driver_governorates parameter', 403);
        }

        $allowedIds = explode(',', $allowedGovernorates);

        // Get driver's governorates from their trips
        $driverGovernorates = Trip::where('driver_id', $driver->user_id)
            ->distinct()
            ->pluck('start_governorate_id')
            ->merge(
                Trip::where('driver_id', $driver->user_id)
                    ->distinct()
                    ->pluck('end_governorate_id')
            )
            ->unique()
            ->toArray();

        if (empty(array_intersect($driverGovernorates, $allowedIds))) {
            throw new RuntimeException('Employee does not have access to this driver\'s governorates', 403);
        }
    }

    private function calculateSummary(Collection $trips, Collection $bookings, float $driverRating): array
    {
        $tripStatusCounts = $trips->groupBy('status.status_key');

        $pending = $tripStatusCounts->get(TripStatus::PENDING, collect())->count();
        $active = $tripStatusCounts->get(TripStatus::ACTIVE, collect())->count();
        $completed = $tripStatusCounts->get(TripStatus::COMPLETED, collect())->count() +
                    $tripStatusCounts->get(TripStatus::AUTO_COMPLETED, collect())->count();

        $cancelledBookings = $bookings->filter(function ($booking) {
            return ($booking->status->status_key ?? '') === 'canceled' ||
                   ($booking->trip->status->status_key ?? '') === 'canceled';
        });

        $cancelledByDriver = 0;
        $cancelledByPassenger = 0;

        foreach ($cancelledBookings as $booking) {
            if ($booking->cancellation) {
                $cancellerId = $booking->cancellation->canceled_by;
                $driverId = $booking->trip->driver_id ?? null;
                if ($cancellerId == $driverId) {
                    $cancelledByDriver++;
                } else {
                    $cancelledByPassenger++;
                }
            } else {
                $cancelledByPassenger++;
            }
        }

        $totalRides = $pending + $active + $completed + $cancelledBookings->count();
        $cancellationRate = $totalRides > 0 ? round((($cancelledByDriver + $cancelledByPassenger) / $totalRides) * 100, 2) : 0;

        $performanceClassification = $this->classifyPerformance($driverRating);

        return [
            'pending_rides' => $pending,
            'active_rides' => $active,
            'completed_rides' => $completed,
            'cancelled_by_driver' => $cancelledByDriver,
            'cancelled_by_passenger' => $cancelledByPassenger,
            'total_rides' => $totalRides,
            'cancellation_rate' => $cancellationRate,
            'performance_classification' => $performanceClassification,
            'performance_classification_display' => $this->performanceClassificationDisplay($performanceClassification),
        ];
    }

    private function classifyPerformance(float $rating): string
    {
        if ($rating >= 4) {
            return 'Good';
        } elseif ($rating >= 2 && $rating < 4) {
            return 'Average';
        } else {
            return 'Low';
        }
    }

    private function performanceClassificationDisplay(string $classification): string
    {
        $language = request()->getPreferredLanguage(['ar', 'en']) ?? 'en';

        return match ($language) {
            'ar' => match ($classification) {
                'Good' => 'جيد',
                'Average' => 'متوسط',
                'Low' => 'ضعيف',
                default => $classification,
            },
            default => $classification,
        };
    }

    private function getRideBreakdown(Collection $bookings): array
    {
        $breakdown = [
            'pending' => [],
            'active' => [],
            'completed' => [],
            'cancelled_by_driver' => [],
            'cancelled_by_passenger' => [],
        ];

        foreach ($bookings as $booking) {
            $tripStatus = $booking->trip->status->status_key ?? 'unknown';
            $bookingStatus = $booking->status->status_key ?? 'unknown';
            $item = [
                'ride_id' => 'R' . $booking->booking_id,
                'created_at' => $booking->created_at->toISOString(),
                'passenger_name' => $booking->passenger->full_name ?? 'Unknown',
            ];

            if ($tripStatus === 'pending') {
                $breakdown['pending'][] = $item;
            } elseif ($tripStatus === 'active') {
                $breakdown['active'][] = $item;
            } elseif (in_array($tripStatus, ['completed', 'auto_completed'])) {
                $breakdown['completed'][] = $item;
            } elseif ($bookingStatus === 'canceled' || $tripStatus === 'canceled') {
                if ($booking->cancellation) {
                    $cancellerId = $booking->cancellation->canceled_by;
                    $driverId = $booking->trip->driver_id ?? null;
                    if ($cancellerId == $driverId) {
                        $breakdown['cancelled_by_driver'][] = $item;
                    } else {
                        $breakdown['cancelled_by_passenger'][] = $item;
                    }
                } else {
                    $breakdown['cancelled_by_passenger'][] = $item;
                }
            }
        }

        return $breakdown;
    }

    private function getDriverGovernorate(Collection $trips): string
    {
        if ($trips->isEmpty()) {
            return 'Unknown';
        }

        // Get most common governorate from trips
        $governorates = collect();
        foreach ($trips as $trip) {
            $governorates->push($trip->start_governorate_id);
            $governorates->push($trip->end_governorate_id);
        }

        $mostCommonId = $governorates->countBy()->sortDesc()->keys()->first();

        $governorate = Governorate::find($mostCommonId);
        return $governorate ? $governorate->name : 'Unknown';
    }
}
