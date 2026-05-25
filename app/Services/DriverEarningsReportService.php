<?php

namespace App\Services;

use App\Models\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DriverEarningsReportService
{
    public function generate(array $filters): array
    {
        $rows = $this->baseQuery($filters)
            ->select([
                'users.user_id as driver_id',
                'users.full_name as driver_name',
                'users.phone as driver_phone',
                'driver_profiles.personal_photo as driver_image',
            ])
            ->selectRaw('COUNT(trips.trip_id) as completed_trips_count')
            ->selectRaw('COALESCE(SUM(trips.gross_revenue_amount), 0) as total_trip_income')
            ->selectRaw('COALESCE(SUM(trips.commission_amount), 0) as total_commission_deducted')
            ->selectRaw('COALESCE(SUM(trips.net_revenue_amount), 0) as net_driver_profit')
            ->groupBy('users.user_id', 'users.full_name', 'users.phone', 'driver_profiles.personal_photo')
            ->orderByDesc('net_driver_profit')
            ->orderBy('users.user_id')
            ->get();

        return [
            'filters' => [
                'driver_name' => $filters['driver_name'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ],
            'summary' => [
                'drivers_count' => $rows->count(),
                'total_completed_trips' => (int) $rows->sum('completed_trips_count'),
                'total_trip_income' => $this->roundMoney($rows->sum('total_trip_income')),
                'total_commission_deducted' => $this->roundMoney($rows->sum('total_commission_deducted')),
                'total_net_driver_profit' => $this->roundMoney($rows->sum('net_driver_profit')),
            ],
            'items' => $rows
                ->map(fn ($row) => [
                    'driver' => [
                        'id' => (int) $row->driver_id,
                        'full_name' => $row->driver_name,
                        'phone' => $row->driver_phone,
                        'image' => $row->driver_image,
                    ],
                    'total_trips' => (int) $row->completed_trips_count,
                    'total_trip_income' => $this->roundMoney($row->total_trip_income),
                    'commission_deducted' => $this->roundMoney($row->total_commission_deducted),
                    'net_profit' => $this->roundMoney($row->net_driver_profit),
                ])
                ->values(),
            'source' => [
                'included_trip_statuses' => [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED],
                'excluded_trip_statuses' => [TripStatus::PENDING, TripStatus::ACTIVE, TripStatus::CANCELED],
                'income_field' => 'trips.gross_revenue_amount',
                'commission_field' => 'trips.commission_amount',
                'net_profit_field' => 'trips.net_revenue_amount',
                'date_field' => 'COALESCE(trips.completed_at, trips.departure_time)',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function baseQuery(array $filters): Builder
    {
        $query = DB::table('trips')
            ->join('trip_statuses', 'trip_statuses.status_id', '=', 'trips.status_id')
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'trips.driver_id')
            ->join('users', 'users.user_id', '=', 'driver_profiles.user_id')
            ->whereIn('trip_statuses.status_key', [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED]);

        if (! empty($filters['driver_name'])) {
            $query->where('users.full_name', 'like', '%'.$filters['driver_name'].'%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereRaw('COALESCE(trips.completed_at, trips.departure_time) >= ?', [
                CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'])
                    ->startOfDay()
                    ->toDateTimeString(),
            ]);
        }

        if (! empty($filters['date_to'])) {
            $query->whereRaw('COALESCE(trips.completed_at, trips.departure_time) <= ?', [
                CarbonImmutable::createFromFormat('Y-m-d', $filters['date_to'])
                    ->endOfDay()
                    ->toDateTimeString(),
            ]);
        }

        return $query;
    }

    private function roundMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
