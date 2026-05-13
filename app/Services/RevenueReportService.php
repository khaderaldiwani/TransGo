<?php

namespace App\Services;

use App\Models\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevenueReportService
{
    public function generate(array $filters): array
    {
        $period = $filters['period'] ?? 'monthly';
        $range = $this->resolveRange($period, $filters);
        $trips = $this->completedTrips($range['date_from'], $range['date_to']);
        $totalRevenue = $this->sumMoney($trips, 'commission_amount');

        return [
            'period' => [
                'type' => $period,
                'date_from' => $range['date_from']->toDateString(),
                'date_to' => $range['date_to']->toDateString(),
                'grouping' => $range['grouping'],
            ],
            'summary' => [
                'total_revenue' => $totalRevenue,
                'completed_trips_count' => $trips->count(),
                'total_commissions' => $totalRevenue,
                'total_wallet_deductions' => $totalRevenue,
                'total_gross_revenue' => $this->sumMoney($trips, 'gross_revenue_amount'),
                'average_daily_revenue' => $this->averageDailyRevenue($totalRevenue, $range['date_from'], $range['date_to']),
            ],
            'source' => [
                'included_trip_statuses' => [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED],
                'excluded_trip_statuses' => [TripStatus::PENDING, TripStatus::ACTIVE, TripStatus::CANCELED],
                'revenue_field' => 'trips.commission_amount',
                'wallet_deductions_source' => 'trips.commission_amount',
            ],
            'chart' => $this->chart($trips, $range['date_from'], $range['date_to'], $range['grouping']),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function resolveRange(string $period, array $filters): array
    {
        $anchor = ! empty($filters['date_from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'])
            : CarbonImmutable::now();

        if ($period === 'custom') {
            return [
                'date_from' => CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'])->startOfDay(),
                'date_to' => CarbonImmutable::createFromFormat('Y-m-d', $filters['date_to'])->endOfDay(),
                'grouping' => 'daily',
            ];
        }

        return match ($period) {
            'daily' => [
                'date_from' => $anchor->startOfDay(),
                'date_to' => $anchor->endOfDay(),
                'grouping' => 'daily',
            ],
            'weekly' => [
                'date_from' => $anchor->startOfWeek()->startOfDay(),
                'date_to' => $anchor->endOfWeek()->endOfDay(),
                'grouping' => 'daily',
            ],
            'yearly' => [
                'date_from' => $anchor->startOfYear()->startOfDay(),
                'date_to' => $anchor->endOfYear()->endOfDay(),
                'grouping' => 'monthly',
            ],
            default => [
                'date_from' => $anchor->startOfMonth()->startOfDay(),
                'date_to' => $anchor->endOfMonth()->endOfDay(),
                'grouping' => 'daily',
            ],
        };
    }

    private function completedTrips(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): Collection
    {
        return DB::table('trips')
            ->join('trip_statuses', 'trip_statuses.status_id', '=', 'trips.status_id')
            ->whereIn('trip_statuses.status_key', [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED])
            ->whereRaw('COALESCE(trips.completed_at, trips.departure_time) >= ?', [$dateFrom->toDateTimeString()])
            ->whereRaw('COALESCE(trips.completed_at, trips.departure_time) <= ?', [$dateTo->toDateTimeString()])
            ->select([
                'trips.trip_id',
                'trips.gross_revenue_amount',
                'trips.commission_amount',
                'trips.net_revenue_amount',
                'trips.commission_percentage',
                'trips.completed_at',
                'trips.departure_time',
            ])
            ->selectRaw('COALESCE(trips.completed_at, trips.departure_time) as revenue_recorded_at')
            ->orderBy('revenue_recorded_at')
            ->get();
    }

    private function chart(Collection $trips, CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $grouping): array
    {
        $buckets = $this->emptyBuckets($dateFrom, $dateTo, $grouping);

        foreach ($trips as $trip) {
            $recordedAt = CarbonImmutable::parse($trip->revenue_recorded_at);
            $key = $grouping === 'monthly'
                ? $recordedAt->format('Y-m')
                : $recordedAt->toDateString();

            if (! isset($buckets[$key])) {
                continue;
            }

            $commission = (float) ($trip->commission_amount ?? 0);

            $buckets[$key]['completed_trips_count']++;
            $buckets[$key]['total_revenue'] = round($buckets[$key]['total_revenue'] + $commission, 2);
            $buckets[$key]['total_commissions'] = $buckets[$key]['total_revenue'];
            $buckets[$key]['total_wallet_deductions'] = $buckets[$key]['total_revenue'];
            $buckets[$key]['total_gross_revenue'] = round($buckets[$key]['total_gross_revenue'] + (float) ($trip->gross_revenue_amount ?? 0), 2);
        }

        return array_values($buckets);
    }

    private function emptyBuckets(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $grouping): array
    {
        $buckets = [];

        if ($grouping === 'monthly') {
            for ($date = $dateFrom->startOfMonth(); $date <= $dateTo; $date = $date->addMonth()) {
                $key = $date->format('Y-m');
                $buckets[$key] = $this->emptyBucket(
                    $key,
                    $date->startOfMonth()->toDateString(),
                    $date->endOfMonth()->toDateString()
                );
            }

            return $buckets;
        }

        for ($date = $dateFrom->startOfDay(); $date <= $dateTo; $date = $date->addDay()) {
            $key = $date->toDateString();
            $buckets[$key] = $this->emptyBucket($key, $key, $key);
        }

        return $buckets;
    }

    private function emptyBucket(string $label, string $dateFrom, string $dateTo): array
    {
        return [
            'label' => $label,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_revenue' => 0.0,
            'completed_trips_count' => 0,
            'total_commissions' => 0.0,
            'total_wallet_deductions' => 0.0,
            'total_gross_revenue' => 0.0,
        ];
    }

    private function sumMoney(Collection $trips, string $field): float
    {
        return round($trips->sum(fn ($trip) => (float) ($trip->{$field} ?? 0)), 2);
    }

    private function averageDailyRevenue(float $totalRevenue, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): float
    {
        $days = $dateFrom->startOfDay()->diffInDays($dateTo->startOfDay()) + 1;

        return $days > 0 ? round($totalRevenue / $days, 2) : 0.0;
    }
}
