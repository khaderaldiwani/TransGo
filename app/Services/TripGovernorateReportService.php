<?php

namespace App\Services;

use App\Models\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TripGovernorateReportService
{
    public function generate(array $filters): array
    {
        $summary = $this->summary($filters);

        return [
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'start_governorate_id' => isset($filters['start_governorate_id'])
                    ? (int) $filters['start_governorate_id']
                    : null,
                'end_governorate_id' => isset($filters['end_governorate_id'])
                    ? (int) $filters['end_governorate_id']
                    : null,
            ],
            'summary' => $summary,
            'by_start_governorate' => $this->groupByGovernorate(
                $filters,
                'trips.start_governorate_id',
                'start',
                $summary['total_trips']
            ),
            'by_end_governorate' => $this->groupByGovernorate(
                $filters,
                'trips.end_governorate_id',
                'end',
                $summary['total_trips']
            ),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function summary(array $filters): array
    {
        $row = $this->withStatusCounts(
            $this->baseTrips($filters)
                ->leftJoin('bookings', 'bookings.trip_id', '=', 'trips.trip_id')
        )
            ->selectRaw('COUNT(DISTINCT trips.trip_id) as total_trips')
            ->selectRaw('COUNT(bookings.booking_id) as bookings_count')
            ->first();

        return [
            'total_trips' => (int) ($row->total_trips ?? 0),
            'pending_trips' => (int) ($row->pending_trips ?? 0),
            'active_trips' => (int) ($row->active_trips ?? 0),
            'completed_trips' => (int) ($row->completed_trips ?? 0),
            'canceled_trips' => (int) ($row->canceled_trips ?? 0),
            'bookings_count' => (int) ($row->bookings_count ?? 0),
        ];
    }

    private function groupByGovernorate(
        array $filters,
        string $governorateColumn,
        string $direction,
        int $totalTrips
    ): array {
        $rows = $this->withStatusCounts(
            $this->baseTrips($filters)
                ->join('governorates as governorates_report', 'governorates_report.governorate_id', '=', $governorateColumn)
                ->leftJoin('bookings', 'bookings.trip_id', '=', 'trips.trip_id')
                ->select([
                    'governorates_report.governorate_id',
                    'governorates_report.name',
                ])
        )
            ->selectRaw('COUNT(DISTINCT trips.trip_id) as total_trips')
            ->selectRaw('COUNT(bookings.booking_id) as bookings_count')
            ->groupBy('governorates_report.governorate_id', 'governorates_report.name')
            ->orderByDesc('total_trips')
            ->orderBy('governorates_report.governorate_id')
            ->get();

        return $rows
            ->map(fn ($row) => $this->formatGovernorateRow($row, $direction, $totalTrips))
            ->values()
            ->all();
    }

    private function baseTrips(array $filters): Builder
    {
        $query = DB::table('trips')
            ->join('trip_statuses', 'trip_statuses.status_id', '=', 'trips.status_id');

        if (! empty($filters['date_from'])) {
            $query->where(
                'trips.departure_time',
                '>=',
                CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'])->startOfDay()
            );
        }

        if (! empty($filters['date_to'])) {
            $query->where(
                'trips.departure_time',
                '<=',
                CarbonImmutable::createFromFormat('Y-m-d', $filters['date_to'])->endOfDay()
            );
        }

        if (! empty($filters['start_governorate_id'])) {
            $query->where('trips.start_governorate_id', (int) $filters['start_governorate_id']);
        }

        if (! empty($filters['end_governorate_id'])) {
            $query->where('trips.end_governorate_id', (int) $filters['end_governorate_id']);
        }

        return $query;
    }

    private function withStatusCounts(Builder $query): Builder
    {
        return $query
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN trip_statuses.status_key = ? THEN trips.trip_id END) as pending_trips',
                [TripStatus::PENDING]
            )
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN trip_statuses.status_key = ? THEN trips.trip_id END) as active_trips',
                [TripStatus::ACTIVE]
            )
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN trip_statuses.status_key IN (?, ?) THEN trips.trip_id END) as completed_trips',
                [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED]
            )
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN trip_statuses.status_key = ? THEN trips.trip_id END) as canceled_trips',
                [TripStatus::CANCELED]
            );
    }

    private function formatGovernorateRow(object $row, string $direction, int $totalTrips): array
    {
        $tripsCount = (int) $row->total_trips;

        return [
            'direction' => $direction,
            'governorate' => [
                'id' => (int) $row->governorate_id,
                'name' => $row->name,
            ],
            'total_trips' => $tripsCount,
            'pending_trips' => (int) $row->pending_trips,
            'active_trips' => (int) $row->active_trips,
            'completed_trips' => (int) $row->completed_trips,
            'canceled_trips' => (int) $row->canceled_trips,
            'bookings_count' => (int) $row->bookings_count,
            'activity_percentage' => $totalTrips > 0
                ? round(($tripsCount / $totalTrips) * 100, 2)
                : 0.0,
        ];
    }
}
