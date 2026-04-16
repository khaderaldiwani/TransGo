<?php

namespace App\Services;

use App\Models\BookingStatus;
use App\Models\Trip;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdminBookingManagementService
{
    public function listBookings(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $query = $this->baseTripQuery()
            ->with(['bookings' => function (Builder $query) use ($filters) {
                $this->applyBookingFilters($query, $filters);
                $query->with([
                    'passenger',
                    'status',
                    'pickupPoint.governorate',
                    'pickupPoint.tripPoint',
                    'payments',
                    'review',
                ])->orderByDesc('created_at');
            }]);

        if ($filters['trip_id'] !== null) {
            $query->where('trip_id', $filters['trip_id']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];

            $query->where(function (Builder $query) use ($search) {
                $query->whereHas('driver.user', function (Builder $driverQuery) use ($search) {
                    $driverQuery->where('full_name', 'like', "%{$search}%");
                })
                ->orWhereHas('bookings', function (Builder $bookingQuery) use ($search) {
                    $bookingQuery->where('booking_code', 'like', "%{$search}%")
                        ->orWhereHas('passenger', function (Builder $passengerQuery) use ($search) {
                            $passengerQuery->where('full_name', 'like', "%{$search}%");
                        });
                });
            });
        }

        $query->whereHas('bookings', function (Builder $bookingQuery) use ($filters) {
            $this->applyBookingFilters($bookingQuery, $filters);
        });

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderBy('departure_time')->paginate($filters['per_page']);

        $items = $paginator->getCollection()
            ->map(fn (Trip $trip) => $this->transformTripWithBookings($trip, $filters))
            ->values();

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
            'items' => $items,
        ];
    }

    private function baseTripQuery(): Builder
    {
        return Trip::query()
            ->with([
                'driver.user',
                'status',
                'startGovernorate',
                'endGovernorate',
            ]);
    }

    private function applyBookingFilters(Builder $query, array $filters): Builder
    {
        if ($filters['status'] !== '') {
            $statusId = BookingStatus::query()
                ->where('status_key', $filters['status'])
                ->value('status_id');

            $query->where('status_id', $statusId ?? 0);
        }

        if ($filters['payment_method'] !== '') {
            $query->where('payment_method', $filters['payment_method']);
        }

        if ($filters['from_date'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if ($filters['to_date'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query;
    }

    private function transformTripWithBookings(Trip $trip, array $filters): array
    {
        $search = $filters['search'];
        $driverMatchesSearch = $search !== '' && $this->containsIgnoreCase($trip->driver?->user?->full_name, $search);

        $bookings = $trip->bookings->filter(function ($booking) use ($search, $driverMatchesSearch) {
            if ($search === '' || $driverMatchesSearch) {
                return true;
            }

            return $this->containsIgnoreCase($booking->booking_code, $search)
                || $this->containsIgnoreCase($booking->passenger?->full_name, $search);
        })->values();

        return [
            'trip_id' => $trip->trip_id,
            'departure' => [
                'at' => optional($trip->departure_time)->toIso8601String(),
                'from' => $trip->startGovernorate?->name,
                'to' => $trip->endGovernorate?->name,
            ],
            'driver' => [
                'id' => $trip->driver?->user_id,
                'full_name' => $trip->driver?->user?->full_name,
                'phone' => $trip->driver?->user?->phone,
            ],
            'status' => [
                'key' => $trip->status?->status_key,
                'name' => $trip->status?->status_name,
            ],
            'bookings_count' => $bookings->count(),
            'bookings' => $bookings->map(function ($booking) {
                $payment = $booking->payments->sortByDesc('payment_id')->first();

                return [
                    'booking_id' => $booking->booking_id,
                    'booking_code' => $booking->booking_code,
                    'booking_type' => $booking->booking_type,
                    'seats_reserved' => (int) $booking->seats_reserved,
                    'payment_method' => $booking->payment_method,
                    'total_amount' => (float) $booking->total_amount,
                    'status' => [
                        'key' => $booking->status?->status_key,
                        'name' => $booking->status?->status_name,
                    ],
                    'passenger' => [
                        'id' => $booking->passenger?->user_id,
                        'full_name' => $booking->passenger?->full_name,
                        'phone' => $booking->passenger?->phone,
                    ],
                    'pickup_point' => [
                        'address' => $booking->pickupPoint?->address,
                        'point_name' => $booking->pickupPoint?->point_name,
                        'meeting_time' => optional($booking->pickupPoint?->meeting_time)->toIso8601String(),
                        'governorate' => $booking->pickupPoint?->governorate?->name,
                    ],
                    'payment' => [
                        'method' => $payment?->payment_method ?? $booking->payment_method,
                        'status' => $payment?->payment_status,
                        'amount' => $payment?->amount !== null ? (float) $payment->amount : (float) $booking->total_amount,
                    ],
                    'created_at' => optional($booking->created_at)->toIso8601String(),
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
            'trip_id' => isset($filters['trip_id']) && $filters['trip_id'] !== null ? (int) $filters['trip_id'] : null,
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
}
