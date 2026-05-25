<?php

namespace App\Services;

use App\Models\TripStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DriverFinancialReportService
{
    public function generate(User $driver, array $filters): array
    {
        $dateFrom = CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'])->startOfDay();
        $dateTo = CarbonImmutable::createFromFormat('Y-m-d', $filters['date_to'])->endOfDay();
        $trips = $this->completedTrips($driver, $dateFrom, $dateTo);
        $grossEarnings = $this->sumMoney($trips, 'gross_revenue_amount');
        $commissionAmount = $this->sumMoney($trips, 'commission_amount');
        $netEarnings = $this->sumMoney($trips, 'net_revenue_amount');

        return [
            'period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'summary' => [
                'completed_trips_count' => $trips->count(),
                'gross_earnings_before_commission' => $grossEarnings,
                'commission_percentage' => $this->weightedCommissionPercentage($grossEarnings, $commissionAmount),
                'commission_amount' => $commissionAmount,
                'net_earnings_after_commission' => $netEarnings,
            ],
            'commission_breakdown' => $this->commissionBreakdown($trips),
            'trips' => $trips
                ->map(fn ($trip) => [
                    'trip_id' => (int) $trip->trip_id,
                    'completed_at' => CarbonImmutable::parse($trip->financial_recorded_at)->toIso8601String(),
                    'gross_earnings_before_commission' => $this->roundMoney($trip->gross_revenue_amount),
                    'commission_percentage' => $this->roundMoney($trip->commission_percentage),
                    'commission_amount' => $this->roundMoney($trip->commission_amount),
                    'net_earnings_after_commission' => $this->roundMoney($trip->net_revenue_amount),
                ])
                ->values(),
            'source' => [
                'included_trip_statuses' => [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED],
                'excluded_trip_statuses' => [TripStatus::PENDING, TripStatus::ACTIVE, TripStatus::CANCELED],
                'gross_earnings_field' => 'trips.gross_revenue_amount',
                'commission_percentage_field' => 'trips.commission_percentage',
                'commission_amount_field' => 'trips.commission_amount',
                'net_earnings_field' => 'trips.net_revenue_amount',
                'date_field' => 'COALESCE(trips.completed_at, trips.departure_time)',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function completedTrips(User $driver, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): Collection
    {
        return DB::table('trips')
            ->join('trip_statuses', 'trip_statuses.status_id', '=', 'trips.status_id')
            ->where('trips.driver_id', $driver->user_id)
            ->whereIn('trip_statuses.status_key', [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED])
            ->whereRaw('COALESCE(trips.completed_at, trips.departure_time) >= ?', [$dateFrom->toDateTimeString()])
            ->whereRaw('COALESCE(trips.completed_at, trips.departure_time) <= ?', [$dateTo->toDateTimeString()])
            ->select([
                'trips.trip_id',
                'trips.gross_revenue_amount',
                'trips.commission_percentage',
                'trips.commission_amount',
                'trips.net_revenue_amount',
                'trips.completed_at',
                'trips.departure_time',
            ])
            ->selectRaw('COALESCE(trips.completed_at, trips.departure_time) as financial_recorded_at')
            ->orderBy('financial_recorded_at')
            ->get();
    }

    private function commissionBreakdown(Collection $trips): array
    {
        return $trips
            ->groupBy(fn ($trip) => (string) $this->roundMoney($trip->commission_percentage))
            ->map(fn (Collection $group, string $percentage) => [
                'commission_percentage' => (float) $percentage,
                'completed_trips_count' => $group->count(),
                'gross_earnings_before_commission' => $this->sumMoney($group, 'gross_revenue_amount'),
                'commission_amount' => $this->sumMoney($group, 'commission_amount'),
                'net_earnings_after_commission' => $this->sumMoney($group, 'net_revenue_amount'),
            ])
            ->sortBy('commission_percentage')
            ->values()
            ->all();
    }

    private function weightedCommissionPercentage(float $grossEarnings, float $commissionAmount): float
    {
        if ($grossEarnings <= 0) {
            return 0.0;
        }

        return round(($commissionAmount / $grossEarnings) * 100, 2);
    }

    private function sumMoney(Collection $trips, string $field): float
    {
        return round($trips->sum(fn ($trip) => (float) ($trip->{$field} ?? 0)), 2);
    }

    private function roundMoney(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
